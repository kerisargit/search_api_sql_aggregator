<?php

namespace Drupal\search_api_sql_aggregator\Service;

use Drupal\Core\Database\Transaction;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_sql_aggregator\Exception\OperationValidationException;
use Drupal\search_api_sql_aggregator\Sql\SessionTimeout;
use Drupal\search_api_sql_aggregator\Sql\SqlBuilder;

class AggregationManager {

  use StringTranslationTrait;

  private const DEFAULT_HIERARCHY_SOURCE = [
    'table'         => 'taxonomy_term__parent',
    'entity_col'    => 'entity_id',
    'parent_col'    => 'parent_target_id',
    'null_value'    => 0,
    'check_deleted' => TRUE,
  ];

  public const RUN_HISTORY_MAX = 20;

  private const MYSQL_SECONDARY_INDEX_WARNING_THRESHOLD = 55;

  protected $database;
  protected $logger;
  protected $state;
  protected $lock;
  protected $entityTypeManager;
  protected $validator;
  protected SqlBuilder $sql;
  protected SessionTimeout $sessionTimeout;

  protected ?array $tableDescriptions = NULL;

  /**
   * @var \Drupal\search_api_sql_aggregator\Service\UnsafeCustomSqlHandlerInterface|null
   */
  protected $unsafeHandler;

  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    LockBackendInterface $lock,
    EntityTypeManagerInterface $entity_type_manager,
    OperationValidator $validator,
    ?UnsafeCustomSqlHandlerInterface $unsafe_handler = NULL
  ) {
    $this->database          = $database;
    $this->logger            = $logger_factory->get('search_api_sql_aggregator');
    $this->state             = $state;
    $this->lock              = $lock;
    $this->entityTypeManager = $entity_type_manager;
    $this->validator         = $validator;
    $this->unsafeHandler     = $unsafe_handler;
    $this->sql               = new SqlBuilder($database);
    $this->sessionTimeout    = new SessionTimeout($database, $this->logger);
  }

  /**
   * Why nothing can run on this site at all, or NULL if it can.
   */
  public function unavailableReason(): ?string {
    try {
      $this->validator->assertSupportedDriver();
      $this->validator->assertSqlIndexesAvailable();
      return NULL;
    }
    catch (\Throwable $e) {
      return $e->getMessage();
    }
  }

  /**
   * Saved operations: [] for anything that is not a JSON list of objects
   * (missing, corrupted, or a non-string value after a bad config import).
   */
  public static function decodeBlocks($json): array {
    if (!\is_string($json) || $json === '') {
      return [];
    }
    $blocks = \json_decode($json, TRUE);
    return \is_array($blocks) ? $blocks : [];
  }

  public function getUnsafeCustomSqlHandler(): ?UnsafeCustomSqlHandlerInterface {
    return $this->unsafeHandler;
  }

  public function getSchemaData(): array {
    try {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadMultiple();

      if (empty($indexes)) {
        return [];
      }

      $serverLabels = [];

      $tables = [];
      foreach ($indexes as $index) {
        $indexId    = $index->id();
        $indexLabel = $index->label();
        $serverId   = $index->getServerId() ?? 'unknown';
        $base       = OperationValidator::SAFE_TABLE_PREFIX . $indexId;

        if (!isset($serverLabels[$serverId])) {
          try {
            $serverLabels[$serverId] = $index->getServerInstance()->label();
          }
          catch (\Throwable $e) {
            $serverLabels[$serverId] = $serverId;
          }
        }
        $serverLabel = $serverLabels[$serverId];

        $mainCols = [
          ['name' => 'item_id',               'type' => 'varchar', 'label' => 'item_id',               'forbidden' => TRUE],
          ['name' => 'search_api_id',          'type' => 'varchar', 'label' => 'search_api_id',          'forbidden' => TRUE],
          ['name' => 'search_api_datasource',  'type' => 'varchar', 'label' => 'search_api_datasource',  'forbidden' => TRUE],
          ['name' => 'search_api_language',    'type' => 'varchar', 'label' => 'search_api_language',    'forbidden' => TRUE],
        ];
        foreach ($index->getFields() as $fid => $field) {
          $mainCols[] = [
            'name'      => $fid,
            'type'      => $field->getType(),
            'label'     => $field->getLabel(),
            'forbidden' => \in_array($fid, OperationValidator::FORBIDDEN_COLUMNS, TRUE),
          ];
        }
        $tables[] = [
          'name'         => $base,
          'label'        => $indexLabel . ' — Main',
          'kind'         => 'main',
          'index_id'     => $indexId,
          'index_label'  => $indexLabel,
          'server_id'    => $serverId,
          'server_label' => $serverLabel,
          'columns'      => $mainCols,
        ];

        $fieldTables = $this->validator->getIndexDbInfo($index)['field_tables'] ?? [];
        foreach ($index->getFields() as $fid => $field) {
          $fieldInfo = $fieldTables[$fid] ?? NULL;
          if (!$fieldInfo || empty($fieldInfo['table'])) {
            continue;
          }
          $colType      = $field->getType();
          $propertyPath = $field->getPropertyPath();
          $colBase = [
            ['name' => 'item_id', 'type' => 'varchar', 'label' => 'item_id', 'forbidden' => TRUE],
            ['name' => $fieldInfo['column'] ?? 'value', 'type' => $colType, 'label' => 'value', 'forbidden' => FALSE],
          ];
          $isAggregated = \str_ends_with($fid, '_agg') || \str_ends_with($fid, '_aggregated');
          $tables[] = [
            'name'          => $fieldInfo['table'],
            'label'         => $isAggregated
              ? ($field->getLabel() . ' — Aggregated (' . $fid . ')')
              : ($field->getLabel() . ' (' . $fid . ')'),
            'kind'          => $isAggregated ? 'aggregated' : 'field',
            'index_id'      => $indexId,
            'index_label'   => $indexLabel,
            'server_id'     => $serverId,
            'server_label'  => $serverLabel,
            'property_path' => $propertyPath,
            'columns'       => $colBase,
          ];
        }
      }

      return $tables;
    }
    catch (\Throwable $e) {
      $this->logger->error('getSchemaData failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  public function getIndexDbInfo(IndexInterface $index): array {
    return $this->validator->getIndexDbInfo($index);
  }

  public function validateOperations(array $blocks): array {
    return $this->validator->validateOperations($blocks);
  }

  public function validateStructure(array $blocks): array {
    return $this->validator->validateStructure($blocks);
  }

  public function getDatabaseDriver(): string {
    return $this->database->driver();
  }

  public function computeCustomSqlSignature(array $blocks): string {
    $sqlList = [];
    foreach ($blocks as $block) {
      if (\is_array($block) && ($block['type'] ?? NULL) === 'custom_sql') {
        $sqlList[] = \is_string($block['sql'] ?? NULL) ? \trim($block['sql']) : '';
      }
    }
    if (empty($sqlList)) {
      return '';
    }
    return Crypt::hmacBase64((string) json_encode($sqlList), Settings::getHashSalt());
  }

  public static function customSqlCreateKey(): string {
    return Crypt::hmacBase64('search_api_sql_aggregator_custom_sql_create_v1', Settings::getHashSalt());
  }

  public static function customSqlRunKey(): string {
    return Crypt::hmacBase64('search_api_sql_aggregator_custom_sql_run_v1', Settings::getHashSalt());
  }

  public function customSqlCreateKeyMatches(): bool {
    $configured = (string) Settings::get('search_api_sql_aggregator_custom_sql_create_key', '');
    return $configured !== '' && \hash_equals(self::customSqlCreateKey(), $configured);
  }

  public function customSqlRunKeyMatches(): bool {
    $configured = (string) Settings::get('search_api_sql_aggregator_custom_sql_run_key', '');
    return $configured !== '' && \hash_equals(self::customSqlRunKey(), $configured);
  }

  public function processAggregation(
    array $blocks,
    int $timeLimit = 240,
    int $sqlTimeLimit = 30000,
    string $trigger = 'manual',
    string $customSqlSignature = '',
    string $customSqlUnsafeSignature = ''
  ): ?array {
    if (empty($blocks)) {
      return NULL;
    }

    if (\count($blocks) > OperationValidator::MAX_OPERATIONS) {
      return $this->reportError(\sprintf(
        'Aggregation refused: too many operations (%d). Maximum allowed: %d.',
        \count($blocks),
        OperationValidator::MAX_OPERATIONS
      ), $trigger);
    }

    $lock_ttl  = ($timeLimit > 0 ? (float) $timeLimit : 3600.0) + 60.0;
    $lock_name = 'search_api_sql_aggregator_run';
    try {
      $acquired = $this->lock->acquire($lock_name, $lock_ttl);
    }
    catch (\Throwable $e) {
      return $this->reportErrorSafely('Critical: lock backend failed: ' . $e->getMessage(), $trigger);
    }
    if (!$acquired) {
      $this->logger->warning('Aggregation skipped: another run is still in progress (lock held).');
      return NULL;
    }

    $global_start = microtime(TRUE);
    $originalSqlTimeout = NULL;
    $lastRun = NULL;

    try {
      $this->validator->assertSupportedDriver();
      $this->validator->assertSqlIndexesAvailable();

      if (\function_exists('set_time_limit')) {
        set_time_limit($timeLimit);
      }
      $originalSqlTimeout = $this->sessionTimeout->set($sqlTimeLimit);

      $allowed_tables = $this->validator->getAllSearchApiTables();
      if (empty($allowed_tables)) {
        throw new \Exception(
          'Aggregation aborted: could not build the Search API table whitelist. ' .
          'Ensure at least one Search API index is configured.'
        );
      }

      $expectedCustomSqlSig = $this->computeCustomSqlSignature($blocks);
      $expectedCustomSqlUnsafeSig = $this->unsafeHandler ? $this->unsafeHandler->computeSignature($blocks) : '';

      $log = [];

      foreach ($blocks as $index => $rawOp) {
        if (!\is_array($rawOp)) {
          $this->logger->error('Aggregation: operation #@n is not a JSON object (@type) — skipped.', [
            '@n'    => $index + 1,
            '@type' => \gettype($rawOp),
          ]);
          $log[] = [
            'type'    => 'INVALID',
            'target'  => 'operation #' . ($index + 1),
            'parent'  => 'operation #' . ($index + 1),
            'status'  => 'ERROR',
            'msg'     => 'Operation is not a JSON object.',
            'details' => [],
          ];
          continue;
        }

        $op   = $this->validator->ensureOptions($rawOp);
        $type = \is_string($op['type'] ?? NULL) ? $op['type'] : '';
        $target = $this->truncateForMessage((\in_array($type, ['custom_sql', 'custom_sql_unsafe'], TRUE))
          ? \substr(\is_string($op['sql'] ?? NULL) ? $op['sql'] : 'custom SQL', 0, 60)
          : (\is_string($op['target_table'] ?? NULL) ? $op['target_table'] : '???'));
        $op_log = [
          'type'         => $this->truncateForMessage($type ?: 'unknown'),
          'target'       => $target,
          'target_label' => $this->describeTable($target),
          'parent'       => $target,
          'status'       => 'OK',
          'msg'          => '',
          'details'      => [],
        ];

        $transaction = NULL;

        try {
          switch ($type) {
            case 'custom_sql':
              if (!$this->customSqlRunKeyMatches()) {
                throw new OperationValidationException(
                  'Custom SQL is not activated for execution on this server — settings.php does not ' .
                  "contain a matching \$settings['search_api_sql_aggregator_custom_sql_run_key'] value. " .
                  'This requires action from someone with server/deploy access; see the module help ' .
                  'page for the exact value to set.'
                );
              }
              if (!\hash_equals($expectedCustomSqlSig, $customSqlSignature)) {
                throw new OperationValidationException(
                  'Custom SQL signature missing or invalid — this configuration was not saved ' .
                  'through the settings form by a user holding the Custom SQL permission. ' .
                  'Re-save it via the settings page to restore execution.'
                );
              }
              $result = $this->executeCustomSql($op, $allowed_tables);
              break;

            case 'custom_sql_unsafe':
              if (!$this->unsafeHandler) {
                throw new OperationValidationException(
                  "custom_sql_unsafe: the 'Unsafe Custom SQL' submodule (search_api_sql_aggregator_unsafe) " .
                  'is not installed on this site — this block cannot run. Install the submodule, or ' .
                  'remove this block.'
                );
              }
              if (Settings::get('search_api_sql_aggregator_allow_custom_sql', FALSE) !== TRUE) {
                throw new OperationValidationException(
                  'Custom SQL is disabled by site settings ' .
                  '($settings[\'search_api_sql_aggregator_allow_custom_sql\'] is not TRUE) — this also ' .
                  'disables Unsafe Custom SQL.'
                );
              }
              if (!$this->unsafeHandler->runKeyMatches()) {
                throw new OperationValidationException(
                  'Unsafe Custom SQL is not activated for execution on this server — settings.php does ' .
                  "not contain a matching \$settings['search_api_sql_aggregator_unsafe_custom_sql_run_key'] " .
                  'value. This requires action from someone with server/deploy access; see the module ' .
                  'help page for the exact value to set.'
                );
              }
              if ($expectedCustomSqlUnsafeSig === '' || !\hash_equals($expectedCustomSqlUnsafeSig, $customSqlUnsafeSignature)) {
                throw new OperationValidationException(
                  'Unsafe Custom SQL signature missing or invalid — this configuration was not saved ' .
                  'through the settings form by a user holding the Unsafe Custom SQL permission while ' .
                  "the settings.php creation key was present. Re-save it via the settings page (with " .
                  'the creation key in place) to restore execution.'
                );
              }
              $this->unsafeHandler->validate($op);
              $result = $this->unsafeHandler->execute($op);
              break;

            case 'aggregate':
              $result = $this->executeAggregate($op, $allowed_tables, $transaction);
              break;

            case 'copy':
              $result = $this->executeCopy($op, $allowed_tables, $transaction);
              break;

            case 'null_reset':
              $result = $this->executeNullReset($op, $allowed_tables, $transaction);
              break;

            case 'fill_from_union':
              $result = $this->executeFillFromUnion($op, $allowed_tables, $transaction);
              break;

            case 'priority_fill':
              $result = $this->executePriorityFill($op, $allowed_tables, $transaction);
              break;

            default:
              throw new OperationValidationException(
                "Unknown operation type: '" . $this->truncateForMessage($type) . "'."
              );
          }

          $op_log['msg']     = $result['msg'];
          $op_log['details'] = $result['details'];
          unset($transaction);
        }
        catch (OperationValidationException $e) {
          if (isset($transaction)) {
            $transaction->rollBack();
          }
          $op_log['status'] = 'ERROR';
          $op_log['msg']    = $e->getMessage();
          $this->logger->warning('Operation [@type / @target] rejected by validation: @msg', [
            '@type'   => $type ?: 'unknown',
            '@target' => $target,
            '@msg'    => $e->getMessage(),
          ]);
        }
        catch (\Throwable $e) {
          if (isset($transaction)) {
            $transaction->rollBack();
          }
          $op_log['status'] = 'ERROR';
          $op_log['msg']    = $e->getMessage();
          $this->logger->error('Operation [@type / @target] FAILED unexpectedly: @msg', [
            '@type'   => $type ?: 'unknown',
            '@target' => $target,
            '@msg'    => $e->getMessage(),
          ]);
        }

        $log[] = $op_log;
      }

      $error_count = \count(array_filter($log, fn($l) => $l['status'] === 'ERROR'));
      $total_time  = round(microtime(TRUE) - $global_start, 3);
      $status      = $error_count > 0 ? 'WARNING' : 'SUCCESS';
      $timestamp   = time();

      $lastRun = [
        'timestamp'  => $timestamp,
        'status'     => $status,
        'trigger'    => $trigger,
        'total_time' => $total_time,
        'log'        => $log,
      ];
      $this->state->set('search_api_sql_aggregator.last_run', $lastRun);
      $this->appendRunHistory([
        'timestamp'  => $timestamp,
        'status'     => $status,
        'trigger'    => $trigger,
        'total_time' => $total_time,
        'ops'        => \count($log),
        'errors'     => $error_count,
      ]);
    }
    catch (\Throwable $e) {
      $lastRun = $this->reportErrorSafely('Critical: ' . $e->getMessage(), $trigger);
    }
    finally {
      $this->sessionTimeout->reset($originalSqlTimeout);
      try {
        $this->lock->release($lock_name);
      }
      catch (\Throwable $e) {
        // The lock expires on its own (TTL above).
        $this->logger->warning('Could not release the aggregation lock: @msg', ['@msg' => $e->getMessage()]);
      }
    }
    return $lastRun;
  }

  protected function executeCustomSql(array $op, array $allowed): array {
    if (Settings::get('search_api_sql_aggregator_allow_custom_sql', FALSE) !== TRUE) {
      throw new OperationValidationException(
        'Custom SQL is disabled by site settings ' .
        '($settings[\'search_api_sql_aggregator_allow_custom_sql\'] is not TRUE).'
      );
    }

    $this->validator->validateCustomSqlOp($op, $allowed);

    return $this->runCustomSqlStatement((string) ($op['sql'] ?? ''));
  }

  private function runCustomSqlStatement(string $rawSql): array {
    $sql = \trim($rawSql);
    $sql = rtrim($sql, "; \t\n\r");
    if (\strlen($sql) > 10000) {
      throw new OperationValidationException(
        'Custom SQL exceeds the maximum allowed length (10 000 characters). ' .
        'Split long operations into multiple Custom SQL blocks.'
      );
    }

    $t0 = microtime(TRUE);

    $stmt = $this->database->prepareStatement($sql, [], TRUE);
    $stmt->execute();
    $rows = $stmt->rowCount();

    $time = round(microtime(TRUE) - $t0, 4);

    $this->logger->notice('AUDIT: Custom SQL executed (@rows row(s), @time s): @sql', [
      '@rows' => $rows,
      '@time' => $time,
      '@sql'  => \substr($sql, 0, 200),
    ]);

    return [
      'msg'     => (string) $this->t('@rows row(s) affected in @time s.', ['@rows' => $rows, '@time' => $time]),
      'details' => [
        ['op' => 'CUSTOM SQL', 'target' => \substr($sql, 0, 80), 'rows' => $rows, 'time' => $time . 's'],
      ],
    ];
  }

  protected function executeAggregate(array $op, array $allowed, ?Transaction &$transaction): array {
    $start = microtime(TRUE);

    $this->validator->validateAggregateOp($op, $allowed);

    $targetTable  = (string) ($op['target_table']  ?? '');
    $sourceTables = array_values(array_unique((array) ($op['source_tables'] ?? [])));
    $options      = (array) ($op['options'] ?? []);
    $isHierarchy  = !empty($options['hierarchy']);
    $mainTable    = (string) ($options['main_table']  ?? '');
    $mainColumn   = (string) ($options['main_column'] ?? '');

    $hierSrc = array_merge(
      self::DEFAULT_HIERARCHY_SOURCE,
      \is_array($options['hierarchy_source'] ?? NULL) ? $options['hierarchy_source'] : []
    );

    $details       = [];
    $rows_inserted = 0;
    $rows_updated  = 0;
    $has_unknown   = FALSE;

    $transaction = $this->database->startTransaction();

    $t = microtime(TRUE);
    $n = $this->database->delete($targetTable)->execute();
    $details[] = [
      'op' => 'DELETE', 'target' => $targetTable, 'target_label' => $this->describeTable($targetTable),
      'rows' => '-' . $n, 'time' => $this->elapsed($t),
    ];

    foreach ($sourceTables as $src) {
      $t   = microtime(TRUE);
      $sql = $this->sql->getInsertSql($targetTable, $src, $isHierarchy, $hierSrc);
      $n   = $this->executeSafeSql($sql);
      if ($n === -1) {
        $has_unknown = TRUE;
        $display     = '?';
      }
      else {
        $rows_inserted += $n;
        $display        = '+' . $n;
      }
      $details[] = [
        'op' => 'INSERT', 'target' => $src, 'target_label' => $this->describeTable($src),
        'rows' => $display, 'time' => $this->elapsed($t),
      ];
    }

    if (!empty($mainTable) && !empty($mainColumn)) {
      $t            = microtime(TRUE);
      $escapedCol   = $this->database->escapeField($mainColumn);
      $this->database->query("UPDATE {{$mainTable}} SET {$escapedCol} = NULL");

      $unionParts = array_map(
        fn($s) => "SELECT item_id, value FROM {{$s}}",
        $sourceTables
      );
      $updateSql = $this->sql->getUpdateSql(
        $isHierarchy,
        $mainTable,
        $escapedCol,
        implode(' UNION ALL ', $unionParts),
        $hierSrc
      );
      $n = $this->executeSafeSql($updateSql);

      if ($n === -1) {
        $has_unknown  = TRUE;
        $rows_updated = '?';
      }
      else {
        $rows_updated = $n;
      }
      $details[] = [
        'op'     => 'UPDATE',
        'target' => "Main ({$mainTable}.{$mainColumn})",
        'rows'   => $rows_updated,
        'time'   => $this->elapsed($t),
      ];
    }
    else {
      $details[] = [
        'op'     => 'SKIP',
        'target' => (string) $this->t('Main index (main_table / main_column not configured)'),
        'rows'   => '-',
        'time'   => '-',
      ];
    }

    $dropIndexFor = (array) ($options['drop_index_for_sources'] ?? []);
    if ($dropIndexFor) {
      // Commit first: DROP INDEX implicitly commits on MySQL/MariaDB anyway,
      // after which a rollback silently does nothing.
      $transaction = NULL;
      foreach ($this->dropSourceIndexesIfRequested($dropIndexFor) as $detail) {
        $details[] = $detail;
      }
    }

    $elapsed = round(microtime(TRUE) - $start, 3);
    $hasMain = !empty($mainTable) && !empty($mainColumn);
    if ($has_unknown) {
      $msg = (string) $this->t('@elapsed s | (?)', ['@elapsed' => $elapsed]);
    }
    elseif ($hasMain) {
      $msg = (string) $this->t('@elapsed s | Ins: @ins | Upd: @upd', [
        '@elapsed' => $elapsed,
        '@ins'     => $rows_inserted,
        '@upd'     => $rows_updated,
      ]);
    }
    else {
      $msg = (string) $this->t('@elapsed s | Ins: @ins', [
        '@elapsed' => $elapsed,
        '@ins'     => $rows_inserted,
      ]);
    }

    return ['details' => $details, 'msg' => $msg];
  }

  protected function dropSourceIndexesIfRequested(array $tables): array {
    $details = [];

    if ($this->database->driver() !== 'mysql') {
      $details[] = [
        'op'     => 'SKIP',
        'target' => (string) $this->t('Drop source index(es): @list', ['@list' => \implode(', ', $tables)]),
        'rows'   => (string) $this->t('N/A on this driver'),
        'time'   => '-',
      ];
      return $details;
    }

    $touchedIndexIds = [];
    foreach ($tables as $table) {
      $t = microtime(TRUE);
      $resolved = $this->resolveDenormalizedColumnFor($table);
      if (!$resolved) {
        $details[] = [
          'op' => 'SKIP', 'target' => $this->describeTable($table) ?? $table,
          'rows' => (string) $this->t('Could not resolve a denormalized column — nothing to drop'),
          'time' => $this->elapsed($t),
        ];
        continue;
      }

      $indexName = '_' . $resolved['column'];
      $touchedIndexIds[$resolved['index_id']] = TRUE;
      try {
        if ($this->database->schema()->indexExists($resolved['denormalized_table'], $indexName)) {
          $this->database->schema()->dropIndex($resolved['denormalized_table'], $indexName);
          $rows = (string) $this->t('Dropped');
        }
        else {
          $rows = (string) $this->t('Already absent');
        }
      }
      catch (\Exception $e) {
        $this->logger->warning(
          'Could not drop index @index on @table: @msg',
          ['@index' => $indexName, '@table' => $resolved['denormalized_table'], '@msg' => $e->getMessage()]
        );
        $rows = (string) $this->t('Failed — see log');
      }

      $details[] = [
        'op' => 'DROP INDEX', 'target' => $this->describeTable($table) ?? $table,
        'rows' => $rows, 'time' => $this->elapsed($t),
      ];
    }

    foreach (\array_keys($touchedIndexIds) as $indexId) {
      $budgetDetail = $this->checkSecondaryIndexBudget($indexId);
      if ($budgetDetail) {
        $details[] = $budgetDetail;
      }
    }

    return $details;
  }

  protected function resolveDenormalizedColumnFor(string $sourceTable): ?array {
    foreach ($this->getSchemaData() as $row) {
      if (($row['kind'] ?? 'main') === 'main' || ($row['name'] ?? NULL) !== $sourceTable) {
        continue;
      }
      foreach ($row['columns'] ?? [] as $col) {
        if (($col['label'] ?? '') === 'value' && !empty($col['name'])) {
          return [
            'denormalized_table' => OperationValidator::SAFE_TABLE_PREFIX . $row['index_id'],
            'column'             => $col['name'],
            'index_id'           => $row['index_id'],
          ];
        }
      }
      return NULL;
    }
    return NULL;
  }

  protected function checkSecondaryIndexBudget(string $indexId): ?array {
    $table = OperationValidator::SAFE_TABLE_PREFIX . $indexId;
    try {
      $count = (int) $this->database->query(
        "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME != 'PRIMARY'",
        [':table' => $table]
      )->fetchField();
    }
    catch (\Exception $e) {
      return NULL;
    }

    if ($count < self::MYSQL_SECONDARY_INDEX_WARNING_THRESHOLD) {
      return NULL;
    }

    $this->logger->warning(
      '@table now has @count of the MySQL/MariaDB 64-secondary-index ceiling in use — consider dropping more source indexes (options.drop_index_for_sources) or splitting fields across additional indexes before this starts failing field additions.',
      ['@table' => $table, '@count' => $count]
    );
    return [
      'op' => 'WARNING',
      'target' => (string) $this->t('Secondary index budget on @table', ['@table' => $table]),
      'rows' => (string) $this->t('@count / 64 used', ['@count' => $count]),
      'time' => '-',
    ];
  }

  protected function executeCopy(array $op, array $allowed, ?Transaction &$transaction): array {
    $start = microtime(TRUE);

    $this->validator->validateCopyOp($op, $allowed);

    $srcTable = (string) $op['source_table'];
    $srcCol   = (string) $op['source_column'];
    $tgtTable = (string) $op['target_table'];
    $tgtCol   = (string) $op['target_column'];
    $joinType = strtoupper((string) ($op['join_type'] ?? 'INNER'));
    $coalesce = !empty($op['coalesce']);

    $transaction = $this->database->startTransaction();

    $t   = microtime(TRUE);
    $sql = $this->sql->getCopySql($srcTable, $srcCol, $tgtTable, $tgtCol, $joinType, $coalesce);
    $n   = $this->executeSafeSql($sql);

    $elapsed = round(microtime(TRUE) - $start, 3);
    $display = $n === -1 ? '?' : $n;
    $opLabel = ($joinType !== 'INNER' ? $joinType . ' ' : '') . 'COPY' . ($coalesce ? ' + COALESCE' : '');

    $srcDesc = $this->describeTable($srcTable);
    $tgtDesc = $this->describeTable($tgtTable);
    $targetLabel = ($srcDesc || $tgtDesc)
      ? ($srcDesc ?? $srcTable) . ' → ' . ($tgtDesc ?? $tgtTable)
      : NULL;

    $details = [[
      'op'           => $opLabel,
      'target'       => "{$srcTable}.{$srcCol} → {$tgtTable}.{$tgtCol}",
      'target_label' => $targetLabel,
      'rows'         => $display,
      'time'         => $this->elapsed($t),
    ]];
    $msg = (string) $this->t('@elapsed s | Upd: @rows', ['@elapsed' => $elapsed, '@rows' => $display]);

    return ['details' => $details, 'msg' => $msg];
  }

  protected function executeNullReset(array $op, array $allowed, ?Transaction &$transaction): array {
    $start = microtime(TRUE);

    $this->validator->validateNullResetOp($op, $allowed);

    $tgtTable = (string) $op['target_table'];
    $tgtCol   = (string) $op['target_column'];
    $escTgt   = $this->database->escapeField($tgtCol);

    $transaction = $this->database->startTransaction();

    $t = microtime(TRUE);
    $this->database->query("UPDATE {{$tgtTable}} SET {$escTgt} = NULL");

    $elapsed = round(microtime(TRUE) - $start, 3);
    $details = [[
      'op'           => 'NULL RESET',
      'target'       => "{$tgtTable}.{$tgtCol}",
      'target_label' => $this->describeTable($tgtTable),
      'rows'         => '-',
      'time'         => $this->elapsed($t),
    ]];
    $msg = (string) $this->t('@elapsed s', ['@elapsed' => $elapsed]);

    return ['details' => $details, 'msg' => $msg];
  }

  protected function executeFillFromUnion(array $op, array $allowed, ?Transaction &$transaction): array {
    $start = microtime(TRUE);

    $this->validator->validateFillFromUnionOp($op, $allowed);

    $tgtTable = (string) $op['target_table'];
    $tgtCol   = (string) $op['target_column'];
    $sources  = (array)  $op['sources'];
    $aggFunc  = strtoupper((string) ($op['aggregate_func'] ?? 'MIN'));
    $joinType = strtoupper((string) ($op['join_type']      ?? 'INNER'));
    $distinct = !empty($op['distinct']);

    $escTgt = $this->database->escapeField($tgtCol);

    $unionParts = [];
    foreach ($sources as $src) {
      $escSrcCol    = $this->database->escapeField((string) $src['column']);
      $srcTable     = (string) $src['table'];
      $sel          = $distinct ? 'SELECT DISTINCT' : 'SELECT';
      $unionParts[] = "{$sel} item_id, {$escSrcCol} AS val FROM {{$srcTable}}";
    }

    $transaction = $this->database->startTransaction();

    $t   = microtime(TRUE);
    $sql = $this->sql->getFillFromUnionSql($tgtTable, $escTgt, $unionParts, $aggFunc, $joinType);
    $n   = $this->executeSafeSql($sql);

    $elapsed = round(microtime(TRUE) - $start, 3);
    $display = $n === -1 ? '?' : $n;
    $opLabel = ($joinType !== 'INNER' ? $joinType . ' ' : '')
      . "FILL ({$aggFunc})"
      . ($distinct ? ' DISTINCT' : '');

    $details = [[
      'op'           => $opLabel,
      'target'       => "{$tgtTable}.{$tgtCol}",
      'target_label' => $this->describeTable($tgtTable),
      'rows'         => $display,
      'time'         => $this->elapsed($t),
    ]];
    $msg = (string) $this->t('@elapsed s | Upd: @rows', ['@elapsed' => $elapsed, '@rows' => $display]);

    return ['details' => $details, 'msg' => $msg];
  }

  protected function executePriorityFill(array $op, array $allowed, ?Transaction &$transaction): array {
    $start = microtime(TRUE);

    $this->validator->validatePriorityFillOp($op, $allowed);

    $tgtTable = (string) $op['target_table'];
    $tgtCol   = (string) $op['target_column'];
    $sources  = (array)  $op['sources'];
    $escTgt   = $this->database->escapeField($tgtCol);

    $transaction = $this->database->startTransaction();

    $t   = microtime(TRUE);
    $sql = $this->sql->getPriorityFillSql($tgtTable, $escTgt, $sources);
    $n   = $this->executeSafeSql($sql);

    $elapsed = round(microtime(TRUE) - $start, 3);
    $display = $n === -1 ? '?' : $n;

    $details = [[
      'op'           => 'PRIORITY FILL',
      'target'       => "{$tgtTable}.{$tgtCol}",
      'target_label' => $this->describeTable($tgtTable),
      'rows'         => $display,
      'time'         => $this->elapsed($t),
    ]];
    $msg = (string) $this->t('@elapsed s | Upd: @rows', ['@elapsed' => $elapsed, '@rows' => $display]);

    return ['details' => $details, 'msg' => $msg];
  }

  protected function executeSafeSql(string $sql): int {
    try {
      $stmt = $this->database->prepareStatement($sql, [], TRUE);
    }
    catch (\Exception $e) {
      $this->logger->debug(
        'prepareStatement fell back to query() [@sql…]: @msg',
        ['@sql' => \substr($sql, 0, 80), '@msg' => $e->getMessage()]
      );
      return (int) $this->database->query($sql)->rowCount();
    }
    // Not inside the try: a failing write must not be re-run through
    // query(). On PostgreSQL the retry would only report "current
    // transaction is aborted" instead of the real error.
    $stmt->execute();
    return (int) $stmt->rowCount();
  }

  protected function elapsed(float $since): string {
    return round(microtime(TRUE) - $since, 4) . 's';
  }

  protected function describeTable(string $table): ?string {
    if ($this->tableDescriptions === NULL) {
      $this->tableDescriptions = [];
      foreach ($this->getSchemaData() as $row) {
        if (!empty($row['property_path']) && !empty($row['name'])) {
          $label = (string) ($row['label'] ?? $row['name']);
          $this->tableDescriptions[$row['name']] = $label . ' (' . $row['property_path'] . ')';
        }
      }
    }
    return $this->tableDescriptions[$table] ?? NULL;
  }

  protected function truncateForMessage(string $value): string {
    $value = \preg_replace('/[\'\p{Cc}\p{Cf}]/u', '', $value) ?? $value;
    return \mb_strlen($value) > OperationValidator::MAX_MESSAGE_VALUE_LENGTH
      ? \mb_substr($value, 0, OperationValidator::MAX_MESSAGE_VALUE_LENGTH) . '…'
      : $value;
  }

  /**
   * reportError() that cannot throw: State may be what is broken.
   */
  protected function reportErrorSafely(string $msg, string $trigger): array {
    try {
      return $this->reportError($msg, $trigger);
    }
    catch (\Throwable $e) {
      try {
        $this->logger->critical('@msg (could not record it: @why)', ['@msg' => $msg, '@why' => $e->getMessage()]);
      }
      catch (\Throwable) {
      }
      return ['timestamp' => time(), 'status' => 'ERROR', 'trigger' => $trigger, 'log' => []];
    }
  }

  protected function reportError(string $msg, string $trigger = 'manual'): array {
    $this->logger->critical($msg);
    $timestamp = time();
    $lastRun = [
      'timestamp' => $timestamp,
      'status'    => 'ERROR',
      'trigger'   => $trigger,
      'log'       => [[
        'type'    => 'SYSTEM',
        'target'  => 'SYSTEM',
        'parent'  => 'SYSTEM',
        'status'  => 'ERROR',
        'msg'     => $msg,
        'details' => [],
      ]],
    ];
    $this->state->set('search_api_sql_aggregator.last_run', $lastRun);
    $this->appendRunHistory([
      'timestamp'  => $timestamp,
      'status'     => 'ERROR',
      'trigger'    => $trigger,
      'total_time' => NULL,
      'ops'        => 0,
      'errors'     => 1,
    ]);
    return $lastRun;
  }

  protected function appendRunHistory(array $entry): void {
    $history = $this->state->get('search_api_sql_aggregator.run_history', []);
    if (!\is_array($history)) {
      $history = [];
    }
    array_unshift($history, $entry);
    $this->state->set(
      'search_api_sql_aggregator.run_history',
      \array_slice($history, 0, self::RUN_HISTORY_MAX)
    );
  }

}
