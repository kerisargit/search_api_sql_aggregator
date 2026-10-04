<?php

namespace Drupal\search_api_sql_aggregator\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\DataType\DataTypePluginManager;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_sql_aggregator\Exception\OperationValidationException;
use Drupal\search_api_sql_aggregator\Sql\CustomSqlClassifier;

class OperationValidator {

  use StringTranslationTrait;

  public const SAFE_TABLE_PREFIX = 'search_api_db_';

  private const SEARCH_API_DB_KEY_VALUE_COLLECTION = 'search_api_db.indexes';

  private const DB_BACKEND_ID = 'search_api_db';

  public const FORBIDDEN_COLUMNS = [
    'item_id',
    'search_api_id',
    'search_api_datasource',
    'search_api_language',
    'search_api_relevance',
    'search_api_random',
  ];

  public const ALLOWED_AGG_FUNCS = ['MIN', 'MAX', 'SUM', 'COUNT', 'AVG', 'GROUP_CONCAT'];

  public const CUSTOM_SQL_READONLY_COMMANDS = CustomSqlClassifier::READONLY_COMMANDS;

  public const CUSTOM_SQL_WRITE_COMMANDS = CustomSqlClassifier::WRITE_COMMANDS;

  public const NUMERIC_FIELD_TYPES = ['integer', 'decimal', 'duration', 'boolean', 'date'];

  public const ALLOWED_JOIN_TYPES = ['INNER', 'LEFT'];

  public const MAX_OPERATIONS = 200;

  public const MAX_SOURCES = 50;

  public const SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

  // UPDATE ... FROM needs SQLite 3.33; Drupal core itself accepts 3.26.
  public const SQLITE_MINIMUM_VERSION = '3.33.0';

  public const MAX_MESSAGE_VALUE_LENGTH = 512;

  private const IDENTIFIER_PATTERN = '/\A[a-z0-9_]{1,128}\z/';

  protected $database;
  protected $logger;
  protected $entityTypeManager;
  protected $keyValueFactory;
  protected $dataTypePluginManager;

  protected $fieldTypeMap;

  /**
   * @var \Drupal\search_api_sql_aggregator\Service\UnsafeCustomSqlHandlerInterface|null
   */
  protected $unsafeHandler;

  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    KeyValueFactoryInterface $key_value_factory,
    ?DataTypePluginManager $data_type_plugin_manager = NULL,
    ?UnsafeCustomSqlHandlerInterface $unsafe_handler = NULL
  ) {
    $this->database              = $database;
    $this->logger                = $logger_factory->get('search_api_sql_aggregator');
    $this->entityTypeManager     = $entity_type_manager;
    $this->keyValueFactory       = $key_value_factory;
    $this->dataTypePluginManager = $data_type_plugin_manager;
    $this->unsafeHandler         = $unsafe_handler;
  }

  public function validateOperations(array $blocks): array {
    $results = [];

    try {
      $this->assertSupportedDriver();
      $this->assertSqlIndexesAvailable();
    }
    catch (OperationValidationException $e) {
      return [[
        'status' => 'error',
        'target' => 'Global',
        'msg'    => $e->getMessage(),
      ]];
    }

    $allowed_tables = $this->getAllSearchApiTables();
    if (empty($allowed_tables)) {
      return [[
        'status' => 'error',
        'target' => 'Global',
        'msg'    => (string) $this->t('Cannot build Search API table whitelist. Ensure at least one index is configured.'),
      ]];
    }

    if (\count($blocks) > self::MAX_OPERATIONS) {
      $results[] = [
        'status' => 'error',
        'target' => 'Global Config',
        'msg'    => (string) $this->t('Too many operations (@count). Maximum allowed: @max.', [
          '@count' => \count($blocks),
          '@max'   => self::MAX_OPERATIONS,
        ]),
      ];
      return $results;
    }

    foreach ($blocks as $i => $rawOp) {
      $op   = $this->ensureOptions($rawOp);
      $type = $op['type'] ?? '?';
      $typeStr = \is_string($type) ? $type : $this->typeName($type);
      $ref = (\in_array($type, ['custom_sql', 'custom_sql_unsafe'], TRUE))
        ? \substr(\is_string($op['sql'] ?? NULL) ? $op['sql'] : '', 0, 60)
        : (\is_string($op['target_table'] ?? NULL) ? $op['target_table'] : '?');
      $label = '#' . ($i + 1) . ' [' . $this->truncateForMessage($typeStr) . '] ' . $this->truncateForMessage($ref);

      $ok_msg = (string) $this->t('All checks passed.');

      try {
        switch ($type) {
          case 'custom_sql':
            $this->validateCustomSqlOp($op, $allowed_tables);
            $ok_msg = (string) $this->t('⚠ Custom SQL: write target (if any) is verified against the Search API table whitelist; anything the statement only reads from (JOIN, subqueries, WHERE) is not restricted — verify those manually.');
            break;
          case 'custom_sql_unsafe':
            if (!$this->unsafeHandler) {
              throw new OperationValidationException(
                "custom_sql_unsafe: the 'Unsafe Custom SQL' submodule (search_api_sql_aggregator_unsafe) " .
                'is not installed on this site.'
              );
            }
            $this->unsafeHandler->validate($op);
            $ok_msg = (string) $this->t('⚠⚠ Unsafe Custom SQL: no table or command restriction of any kind — verify manually.');
            break;
          case 'aggregate':       $this->validateAggregateOp($op, $allowed_tables);    break;
          case 'copy':            $this->validateCopyOp($op, $allowed_tables);          break;
          case 'null_reset':      $this->validateNullResetOp($op, $allowed_tables);     break;
          case 'fill_from_union': $this->validateFillFromUnionOp($op, $allowed_tables); break;
          case 'priority_fill':   $this->validatePriorityFillOp($op, $allowed_tables);  break;
          default:
            throw new OperationValidationException("Unknown operation type: '" . $this->truncateForMessage($typeStr) . "'.");
        }
        $results[] = ['status' => 'ok', 'target' => $label, 'msg' => $ok_msg];
      }
      catch (\Exception $e) {
        $results[] = ['status' => 'error', 'target' => $label, 'msg' => $e->getMessage()];
      }
    }

    return $results;
  }

  public function ensureOptions(array $op): array {
    if (!isset($op['options'])) {
      $op['options'] = [];
    }
    return $op;
  }

  public function validateStructure(array $blocks): array {
    $errors = [];
    foreach ($blocks as $i => $block) {
      $label = '#' . ($i + 1);
      if (!\is_array($block)) {
        $errors[] = ['index' => $i, 'msg' => $label . ': operation must be a JSON object, got ' . $this->typeName($block) . '.'];
        continue;
      }
      $type = $block['type'] ?? NULL;
      if (!\is_string($type) || $type === '') {
        $errors[] = ['index' => $i, 'msg' => $label . ": 'type' must be a non-empty string."];
        continue;
      }
      try {
        $this->assertWellFormed($block, $type, $label . ' [' . $type . ']');
      }
      catch (OperationValidationException $e) {
        $errors[] = ['index' => $i, 'msg' => $e->getMessage()];
      }
    }
    return $errors;
  }

  protected function assertWellFormed(array $op, string $type, string $ctx): void {
    switch ($type) {
      case 'aggregate':
        $this->assertStringType($op['target_table'] ?? NULL, 'target_table', $ctx);
        $this->assertListType($op['source_tables'] ?? NULL, 'source_tables', $ctx);
        if (isset($op['options'])) {
          $this->assertObjectType($op['options'], 'options', $ctx);
          $opts = \is_array($op['options']) ? $op['options'] : [];
          $this->assertStringType($opts['main_table']  ?? NULL, 'options.main_table',  $ctx);
          $this->assertStringType($opts['main_column'] ?? NULL, 'options.main_column', $ctx);
          if (isset($opts['hierarchy_source'])) {
            $this->assertObjectType($opts['hierarchy_source'], 'options.hierarchy_source', $ctx);
          }
        }
        break;

      case 'copy':
        $this->assertStringType($op['source_table']  ?? NULL, 'source_table',  $ctx);
        $this->assertStringType($op['source_column'] ?? NULL, 'source_column', $ctx);
        $this->assertStringType($op['target_table']  ?? NULL, 'target_table',  $ctx);
        $this->assertStringType($op['target_column'] ?? NULL, 'target_column', $ctx);
        $this->assertStringType($op['join_type']     ?? NULL, 'join_type',     $ctx);
        break;

      case 'null_reset':
        $this->assertStringType($op['target_table']  ?? NULL, 'target_table',  $ctx);
        $this->assertStringType($op['target_column'] ?? NULL, 'target_column', $ctx);
        break;

      case 'fill_from_union':
      case 'priority_fill':
        $this->assertStringType($op['target_table']  ?? NULL, 'target_table',  $ctx);
        $this->assertStringType($op['target_column'] ?? NULL, 'target_column', $ctx);
        $this->assertStringType($op['aggregate_func'] ?? NULL, 'aggregate_func', $ctx);
        $this->assertStringType($op['join_type']      ?? NULL, 'join_type',      $ctx);
        $this->assertListType($op['sources'] ?? NULL, 'sources', $ctx);
        foreach ((array) ($op['sources'] ?? []) as $j => $src) {
          $this->assertObjectType($src, "sources[{$j}]", $ctx);
          if (\is_array($src)) {
            $this->assertStringType($src['table']  ?? NULL, "sources[{$j}].table",  $ctx);
            $this->assertStringType($src['column'] ?? NULL, "sources[{$j}].column", $ctx);
          }
        }
        break;

      case 'custom_sql':
        $this->assertStringType($op['sql'] ?? NULL, 'sql', $ctx);
        break;

      case 'custom_sql_unsafe':
        $this->assertStringType($op['sql'] ?? NULL, 'sql', $ctx);
        if (isset($op['confirmed']) && !\is_bool($op['confirmed'])) {
          throw new OperationValidationException("{$ctx}: 'confirmed' must be a boolean.");
        }
        break;

    }
  }

  protected function assertStringType($value, string $field, string $ctx): void {
    if ($value !== NULL && !\is_string($value)) {
      throw new OperationValidationException(
        "{$ctx}: '{$field}' must be a string, got {$this->typeName($value)}."
      );
    }
  }

  protected function assertListType($value, string $field, string $ctx): void {
    if ($value === NULL) {
      return;
    }
    if (!\is_array($value) || ($value !== [] && !\array_is_list($value))) {
      throw new OperationValidationException(
        "{$ctx}: '{$field}' must be a JSON array, got {$this->typeName($value)}."
      );
    }
  }

  protected function assertObjectType($value, string $field, string $ctx): void {
    if ($value === NULL) {
      return;
    }
    if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
      throw new OperationValidationException(
        "{$ctx}: '{$field}' must be a JSON object, got {$this->typeName($value)}."
      );
    }
  }

  protected function typeName($value): string {
    return \is_array($value)
      ? (\array_is_list($value) ? 'array' : 'object')
      : \strtolower(\gettype($value));
  }

  protected function truncateForMessage(string $value): string {
    $value = \preg_replace('/[\'\p{Cc}\p{Cf}]/u', '', $value) ?? $value;
    return \mb_strlen($value) > self::MAX_MESSAGE_VALUE_LENGTH
      ? \mb_substr($value, 0, self::MAX_MESSAGE_VALUE_LENGTH) . '…'
      : $value;
  }

  public function assertSupportedDriver(): void {
    $driver = $this->database->driver();
    if (!\in_array($driver, self::SUPPORTED_DRIVERS, TRUE)) {
      throw new OperationValidationException(\sprintf(
        "This module explicitly generates dialect-correct SQL for the following database drivers " .
        "only: %s. The active driver is '%s', which is not one of them — this module never falls " .
        "back to generic/untested SQL for an unrecognised driver. Refusing to run rather than emit " .
        "SQL that could fail outright or, worse, execute with different semantics than intended.",
        implode(', ', self::SUPPORTED_DRIVERS),
        $this->truncateForMessage((string) $driver)
      ));
    }
  }

  public function validateAggregateOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'aggregate', 'aggregate');
    $targetTable  = (string) ($op['target_table']  ?? '');
    $sourceTables = (array)  ($op['source_tables'] ?? []);
    $mainTable    = (string) ($op['options']['main_table']  ?? '');
    $mainColumn   = (string) ($op['options']['main_column'] ?? '');

    if (empty($targetTable)) {
      throw new OperationValidationException('aggregate: target_table is required.');
    }
    if (empty($sourceTables)) {
      throw new OperationValidationException('aggregate: source_tables must be a non-empty array.');
    }
    if (\count($sourceTables) > self::MAX_SOURCES) {
      throw new OperationValidationException(\sprintf(
        'aggregate: too many source tables (%d). Maximum: %d.',
        \count($sourceTables),
        self::MAX_SOURCES
      ));
    }

    $this->assertSafeTable($targetTable, 'aggregate target', $allowed);
    // The target is emptied with DELETE before refilling, so make sure it is
    // a field table (item_id, value) and not, say, an index's main table.
    $this->assertSafeColumn('value', $targetTable, 'aggregate target', TRUE);

    foreach ($sourceTables as $src) {
      if (!\is_string($src) || empty($src)) {
        throw new OperationValidationException('aggregate: each entry in source_tables must be a non-empty string.');
      }
      if ($src === $targetTable) {
        throw new OperationValidationException(
          "aggregate: source '" . $this->truncateForMessage($src) . "' equals target — circular reference."
        );
      }
      $this->assertSafeTable($src, "aggregate source '" . $this->truncateForMessage($src) . "'", $allowed);
      $this->assertSafeColumn('value', $src, "aggregate source '" . $this->truncateForMessage($src) . "'");
    }

    if (!empty($mainTable) || !empty($mainColumn)) {
      if (empty($mainTable)) {
        throw new OperationValidationException('aggregate: main_column is set but main_table is missing.');
      }
      if (empty($mainColumn)) {
        throw new OperationValidationException('aggregate: main_table is set but main_column is missing.');
      }
      if ($mainTable === $targetTable) {
        throw new OperationValidationException('aggregate: main_table and target_table cannot be the same.');
      }
      $this->assertSafeTable($mainTable, 'aggregate main_table', $allowed);
      $this->assertSafeColumn($mainColumn, $mainTable, 'aggregate main_column', TRUE);
    }

    $hierSrc = $op['options']['hierarchy_source'] ?? NULL;
    if (!empty($hierSrc) && \is_array($hierSrc)) {
      $this->validateHierarchySource($hierSrc, 'aggregate');
    }

    $dropIndexFor = $op['options']['drop_index_for_sources'] ?? NULL;
    if ($dropIndexFor !== NULL) {
      if (!\is_array($dropIndexFor)) {
        throw new OperationValidationException('aggregate: options.drop_index_for_sources must be an array.');
      }
      foreach ($dropIndexFor as $table) {
        if (!\is_string($table) || $table === '') {
          throw new OperationValidationException('aggregate: each entry in options.drop_index_for_sources must be a non-empty string.');
        }
        if (!\in_array($table, $sourceTables, TRUE)) {
          throw new OperationValidationException(
            "aggregate: options.drop_index_for_sources entry '" . $this->truncateForMessage($table) . "' is not one of this operation's own source_tables."
          );
        }
      }
    }
  }

  public function validateCopyOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'copy', 'copy');
    $srcTable = (string) ($op['source_table']  ?? '');
    $srcCol   = (string) ($op['source_column'] ?? '');
    $tgtTable = (string) ($op['target_table']  ?? '');
    $tgtCol   = (string) ($op['target_column'] ?? '');
    $joinType = strtoupper((string) ($op['join_type'] ?? 'INNER'));

    if (empty($srcTable) || empty($srcCol) || empty($tgtTable) || empty($tgtCol)) {
      throw new OperationValidationException('copy: source_table, source_column, target_table, and target_column are all required.');
    }
    if ($srcTable === $tgtTable && $srcCol === $tgtCol) {
      throw new OperationValidationException('copy: source and target are identical — nothing to do.');
    }
    if (!\in_array($joinType, self::ALLOWED_JOIN_TYPES, TRUE)) {
      throw new OperationValidationException(
        "copy: invalid join_type '" . $this->truncateForMessage($joinType) . "'. Allowed: INNER, LEFT."
      );
    }

    $this->assertSafeTable($srcTable, 'copy source_table', $allowed);
    $this->assertSafeTable($tgtTable, 'copy target_table', $allowed);
    $this->assertSafeColumn($srcCol, $srcTable, 'copy source_column');
    $this->assertSafeColumn($tgtCol, $tgtTable, 'copy target_column', TRUE);
  }

  public function validateNullResetOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'null_reset', 'null_reset');
    $tgtTable = (string) ($op['target_table']  ?? '');
    $tgtCol   = (string) ($op['target_column'] ?? '');

    if (empty($tgtTable) || empty($tgtCol)) {
      throw new OperationValidationException('null_reset: target_table and target_column are required.');
    }

    $this->assertSafeTable($tgtTable, 'null_reset target_table', $allowed);
    $this->assertSafeColumn($tgtCol, $tgtTable, 'null_reset target_column', TRUE);
  }

  public function validateFillFromUnionOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'fill_from_union', 'fill_from_union');
    $tgtTable = (string) ($op['target_table']  ?? '');
    $tgtCol   = (string) ($op['target_column'] ?? '');
    $sources  = (array)  ($op['sources']       ?? []);
    $aggFunc  = strtoupper((string) ($op['aggregate_func'] ?? 'MIN'));
    $joinType = strtoupper((string) ($op['join_type']      ?? 'INNER'));

    if (empty($tgtTable) || empty($tgtCol)) {
      throw new OperationValidationException('fill_from_union: target_table and target_column are required.');
    }
    if (empty($sources)) {
      throw new OperationValidationException('fill_from_union: sources must be a non-empty array.');
    }
    if (\count($sources) > self::MAX_SOURCES) {
      throw new OperationValidationException(\sprintf(
        'fill_from_union: too many sources (%d). Maximum: %d.',
        \count($sources),
        self::MAX_SOURCES
      ));
    }
    if (!\in_array($aggFunc, self::ALLOWED_AGG_FUNCS, TRUE)) {
      throw new OperationValidationException(
        "fill_from_union: aggregate_func '" . $this->truncateForMessage($aggFunc) . "' is not allowed. " .
        'Use one of: ' . implode(', ', self::ALLOWED_AGG_FUNCS) . '.'
      );
    }
    if (!\in_array($joinType, self::ALLOWED_JOIN_TYPES, TRUE)) {
      throw new OperationValidationException(
        "fill_from_union: invalid join_type '" . $this->truncateForMessage($joinType) . "'. Allowed: INNER, LEFT."
      );
    }

    $this->assertSafeTable($tgtTable, 'fill_from_union target_table', $allowed);
    $this->assertSafeColumn($tgtCol, $tgtTable, 'fill_from_union target_column', TRUE);

    $checkNumericType = \in_array($aggFunc, ['SUM', 'AVG'], TRUE);
    $typeMap = $checkNumericType ? $this->getFieldTypeMap() : [];

    foreach ($sources as $i => $src) {
      if (!\is_array($src) || empty($src['table']) || empty($src['column'])) {
        throw new OperationValidationException("fill_from_union: source #{$i} must have 'table' and 'column' keys.");
      }
      $srcTable = (string) $src['table'];
      $srcCol   = (string) $src['column'];
      $this->assertSafeTable($srcTable, "fill_from_union source #{$i} table", $allowed);
      $this->assertSafeColumn($srcCol, $srcTable, "fill_from_union source #{$i} column");

      if ($checkNumericType) {
        $fieldType = $typeMap[$srcTable][$srcCol] ?? NULL;
        if ($fieldType !== NULL && !\in_array($fieldType, self::NUMERIC_FIELD_TYPES, TRUE)) {
          throw new OperationValidationException(
            "fill_from_union: source #{$i} ({$srcTable}.{$srcCol}) has Search API type '{$fieldType}', " .
            "which is stored as text, not a number — {$aggFunc} on it would silently produce a wrong " .
            "result (MySQL coerces non-numeric strings instead of erroring) rather than a clear failure. " .
            'Use MIN, MAX, COUNT, or GROUP_CONCAT for text-typed columns instead.'
          );
        }
      }
    }
  }

  public function validatePriorityFillOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'priority_fill', 'priority_fill');
    $tgtTable = (string) ($op['target_table']  ?? '');
    $tgtCol   = (string) ($op['target_column'] ?? '');
    $sources  = (array)  ($op['sources']       ?? []);

    if (empty($tgtTable) || empty($tgtCol)) {
      throw new OperationValidationException('priority_fill: target_table and target_column are required.');
    }
    if (empty($sources)) {
      throw new OperationValidationException('priority_fill: sources must be a non-empty array.');
    }
    if (\count($sources) > self::MAX_SOURCES) {
      throw new OperationValidationException(\sprintf(
        'priority_fill: too many sources (%d). Maximum: %d.',
        \count($sources),
        self::MAX_SOURCES
      ));
    }

    $this->assertSafeTable($tgtTable, 'priority_fill target_table', $allowed);
    $this->assertSafeColumn($tgtCol, $tgtTable, 'priority_fill target_column', TRUE);

    foreach ($sources as $i => $src) {
      if (!\is_array($src) || empty($src['table']) || empty($src['column'])) {
        throw new OperationValidationException("priority_fill: source #{$i} must have 'table' and 'column' keys.");
      }
      $this->assertSafeTable((string) $src['table'], "priority_fill source #{$i} table", $allowed);
      $this->assertSafeColumn((string) $src['column'], (string) $src['table'], "priority_fill source #{$i} column");
    }
  }

  public function validateCustomSqlOp(array $op, array $allowed): void {
    $this->assertWellFormed($op, 'custom_sql', 'custom_sql');
    ['command' => $command, 'target' => $target] = CustomSqlClassifier::classify((string) ($op['sql'] ?? ''));
    if ($target !== NULL) {
      $this->assertSafeTable($target, "custom_sql {$command} target", $allowed);
    }
  }

  protected function validateHierarchySource(array $src, string $context): void {
    foreach (['table', 'entity_col', 'parent_col'] as $field) {
      if (!isset($src[$field])) {
        continue;
      }
      $value = (string) $src[$field];
      if (empty($value)) {
        throw new OperationValidationException(
          "{$context}: hierarchy_source.{$field} must not be empty."
        );
      }
      if (!preg_match(self::IDENTIFIER_PATTERN, $value)) {
        throw new OperationValidationException(
          "{$context}: hierarchy_source.{$field} '" . $this->truncateForMessage($value) . "' contains invalid characters. " .
          "Only lowercase letters, digits, and underscores are allowed (max 128)."
        );
      }
    }

    if (isset($src['null_value']) && $src['null_value'] !== '' && $src['null_value'] !== NULL) {
      if (!is_numeric($src['null_value'])) {
        throw new OperationValidationException(
          "{$context}: hierarchy_source.null_value must be numeric, got '" .
          $this->truncateForMessage((string) $src['null_value']) . "'."
        );
      }
    }
  }

  protected function assertSafeTable(string $table, string $context, array $allowedTables = []): void {
    $displayTable = $this->truncateForMessage($table);

    if (!preg_match(self::IDENTIFIER_PATTERN, $table)) {
      throw new OperationValidationException(
        "Security [{$context}]: '{$displayTable}' contains invalid characters. " .
        "Only lowercase letters, digits, and underscores are allowed (max 128)."
      );
    }

    if (strpos($table, self::SAFE_TABLE_PREFIX) !== 0) {
      throw new OperationValidationException(
        "Security [{$context}]: '{$displayTable}' does not start with '" . self::SAFE_TABLE_PREFIX . "'. " .
        "This module is only permitted to modify Search API database tables. " .
        "Operation refused."
      );
    }

    if (!$this->database->schema()->tableExists($table)) {
      throw new OperationValidationException(
        "Table [{$context}]: '{$displayTable}' does not exist in the database."
      );
    }

    if (!empty($allowedTables) && !\in_array($table, $allowedTables, TRUE)) {
      throw new OperationValidationException(
        "Security [{$context}]: '{$displayTable}' has the correct prefix but is not " .
        'registered to any Search API index. Refusing to write to an unrecognised table.'
      );
    }
  }

  protected function assertSafeColumn(
    string $col,
    string $table,
    string $context,
    bool $isWriteTarget = FALSE
  ): void {
    $displayCol = $this->truncateForMessage($col);

    if (!preg_match(self::IDENTIFIER_PATTERN, $col)) {
      throw new OperationValidationException(
        "Security [{$context}]: column '{$displayCol}' contains invalid characters. " .
        "Only lowercase letters, digits, and underscores are allowed (max 128)."
      );
    }

    if ($isWriteTarget && \in_array($col, self::FORBIDDEN_COLUMNS, TRUE)) {
      throw new OperationValidationException(
        "Security [{$context}]: column '{$displayCol}' is a protected Search API core column " .
        "and cannot be overwritten."
      );
    }

    if (!$this->database->schema()->fieldExists($table, $col)) {
      throw new OperationValidationException(
        "Column [{$context}]: '{$displayCol}' does not exist in table '{$table}'."
      );
    }
  }

  public function getIndexDbInfo(IndexInterface $index): array {
    try {
      $dbInfo = $this->keyValueFactory->get(self::SEARCH_API_DB_KEY_VALUE_COLLECTION)->get($index->id(), []);
    }
    catch (\Throwable $e) {
      return [];
    }
    if (empty($dbInfo)) {
      return [];
    }
    if (($dbInfo['server'] ?? NULL) !== $index->getServerId()) {
      return [];
    }
    return $dbInfo;
  }

  /**
   * Indexes this module can work on.
   *
   * Only the Database backend has SQL tables (Solr/Elasticsearch do not), and
   * only tables in Drupal's own connection are reachable through
   * $this->database.
   *
   * @return \Drupal\search_api\IndexInterface[]
   */
  public function getSqlIndexes(): array {
    return \array_filter($this->loadIndexes(), fn(IndexInterface $index) => $this->usesDrupalDatabase($index));
  }

  /**
   * @return \Drupal\search_api\IndexInterface[]
   */
  protected function loadIndexes(): array {
    try {
      return $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not load Search API indexes: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  protected function serverOf(IndexInterface $index): ?ServerInterface {
    try {
      return $index->getServerInstance();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  protected function usesDrupalDatabase(IndexInterface $index): bool {
    $server = $this->serverOf($index);
    if (!$server || $server->getBackendId() !== self::DB_BACKEND_ID) {
      return FALSE;
    }
    $key = \explode(':', (string) ($server->getBackendConfig()['database'] ?? ''), 2)[0];
    return $key === $this->database->getKey();
  }

  /**
   * One upfront check instead of an error per operation.
   *
   * @throws \Drupal\search_api_sql_aggregator\Exception\OperationValidationException
   *   With a single reason why nothing can run.
   */
  public function assertSqlIndexesAvailable(): void {
    $all = $this->loadIndexes();
    $sql = \array_filter($all, fn(IndexInterface $index) => $this->usesDrupalDatabase($index));

    if (!$sql) {
      $otherConnection = \array_filter($all, function (IndexInterface $index) {
        $server = $this->serverOf($index);
        return $server && $server->getBackendId() === self::DB_BACKEND_ID;
      });
      throw new OperationValidationException($otherConnection
        ? 'The Search API Database server(s) of index(es) ' . \implode(', ', \array_keys($otherConnection)) .
          " store their tables in another database connection than Drupal's; this module can only work on Drupal's own database."
        : 'No Search API index uses the Database backend (search_api_db), so there are no SQL tables to work on ' .
          '(Solr/Elasticsearch indexes have none).'
      );
    }

    foreach ($sql as $index) {
      if ($this->database->schema()->tableExists(self::SAFE_TABLE_PREFIX . $index->id())) {
        return;
      }
    }
    throw new OperationValidationException(
      'None of the Database-backend indexes (' . \implode(', ', \array_keys($sql)) . ") has its tables in Drupal's database."
    );
  }

  public function getAllSearchApiTables(): array {
    try {
      $indexes = $this->getSqlIndexes();

      if (empty($indexes)) {
        $this->logger->error('No Search API Database-backend indexes found — whitelist is empty, aggregation refused.');
        return [];
      }

      $tables = [];
      foreach ($indexes as $index) {
        $tables[] = self::SAFE_TABLE_PREFIX . $index->id();
        foreach ($this->getIndexDbInfo($index)['field_tables'] ?? [] as $fieldInfo) {
          if (!empty($fieldInfo['table'])) {
            $tables[] = $fieldInfo['table'];
          }
        }
      }

      return array_unique($tables);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Cannot build Search API table whitelist: @msg — aggregation will be refused.',
        ['@msg' => $e->getMessage()]
      );
      return [];
    }
  }

  protected function getFieldTypeMap(): array {
    if ($this->fieldTypeMap !== NULL) {
      return $this->fieldTypeMap;
    }
    $map = [];
    try {
      $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
      foreach ($indexes as $index) {
        $base = self::SAFE_TABLE_PREFIX . $index->id();
        $fieldTables = $this->getIndexDbInfo($index)['field_tables'] ?? [];
        foreach ($index->getFields() as $fieldId => $field) {
          $type = $this->resolveBaseFieldType($field->getType());
          $map[$base][$fieldId] = $type;
          $fieldInfo = $fieldTables[$fieldId] ?? NULL;
          if ($fieldInfo && !empty($fieldInfo['table'])) {
            $map[$fieldInfo['table']][$fieldInfo['column'] ?? 'value'] = $type;
          }
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('Could not build field type map: @msg', ['@msg' => $e->getMessage()]);
      $map = [];
    }
    $this->fieldTypeMap = $map;
    return $map;
  }

  protected function resolveBaseFieldType(string $type): string {
    if (!$this->dataTypePluginManager) {
      return $type;
    }
    try {
      $dataType = $this->dataTypePluginManager->createInstance($type);
      if ($dataType && !$dataType->isDefault()) {
        $fallback = $dataType->getFallbackType();
        if ($fallback !== $type) {
          return $this->resolveBaseFieldType($fallback);
        }
      }
    }
    catch (\Throwable $e) {
    }
    return $type;
  }

}
