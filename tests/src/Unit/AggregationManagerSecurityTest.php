<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Drupal\Tests\UnitTestCase;

class TestableOperationValidator extends OperationValidator {

  private array $searchApiTables = [];

  public function setSearchApiTables(array $tables): void {
    $this->searchApiTables = $tables;
  }

  public function getAllSearchApiTables(): array {
    return $this->searchApiTables;
  }

  // The table list above stands in for real Search API indexes.
  public function assertSqlIndexesAvailable(): void {}

  public function pubAssertSafeTable(string $t, string $ctx, array $allowed = []): void {
    $this->assertSafeTable($t, $ctx, $allowed);
  }

  public function pubAssertSafeColumn(string $c, string $t, string $ctx, bool $write = FALSE): void {
    $this->assertSafeColumn($c, $t, $ctx, $write);
  }
}

/**
 * @group search_api_sql_aggregator
 * @group security
 */
class AggregationManagerSecurityTest extends UnitTestCase {

  use StringTranslationContainerTrait;

  /** @var TestableOperationValidator */
  private TestableOperationValidator $mgr;

  /** @var \PHPUnit\Framework\MockObject\MockObject */
  private $schema;

  private array $existingTables = [
    'search_api_db_idx',
    'search_api_db_idx_field_a',
    'search_api_db_idx_field_b',
    'search_api_db_idx_field_agg',
  ];
  private array $existingCols = [
    'search_api_db_idx'           => ['field_a' => TRUE, 'field_b' => TRUE],
    'search_api_db_idx_field_a'   => ['item_id' => TRUE, 'value' => TRUE],
    'search_api_db_idx_field_b'   => ['item_id' => TRUE, 'value' => TRUE],
    'search_api_db_idx_field_agg' => ['item_id' => TRUE, 'value' => TRUE],
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->setUpStringTranslationContainer();

    $this->schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $this->schema->method('tableExists')
      ->willReturnCallback(fn(string $t) => in_array($t, $this->existingTables, TRUE));
    $this->schema->method('fieldExists')
      ->willReturnCallback(
        fn(string $t, string $c) => isset($this->existingCols[$t][$c])
      );

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($this->schema);
    $db->method('driver')->willReturn('mysql');

    $logger  = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $this->mgr = new TestableOperationValidator(
      $db,
      $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );
    $this->mgr->setSearchApiTables($this->existingTables);
  }

  /**
   * @dataProvider provideValidTableNames
   */
  public function testLayer1AcceptsValidTableNames(string $table): void {
    $this->mgr->pubAssertSafeTable($table, 'test');
    $this->addToAssertionCount(1);
  }

  public static function provideValidTableNames(): array {
    return [
      'lowercase_alpha'      => ['search_api_db_idx'],
      'with_digits'          => ['search_api_db_idx_field_a'],
      'with_underscores'     => ['search_api_db_idx_field_b'],
      'long_name'            => ['search_api_db_idx_field_agg'],
    ];
  }

  /**
   * @dataProvider provideInvalidTableNamesSyntax
   */
  public function testLayer1RejectsInvalidSyntax(string $table): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeTable($table, 'test');
  }

  public static function provideInvalidTableNamesSyntax(): array {
    return [
      'uppercase_letter'            => ['search_api_db_IDX'],
      'empty_string'                => [''],
      'uppercase_prefix'            => ['Xsearch_api_db_idx'],
      'space_in_name'               => ['search_api_db_idx field'],
      'semicolon_injection'         => ["search_api_db_idx; DROP TABLE users"],
      'dash_in_name'                => ['search_api_db_idx-field'],
      'dot_traversal'               => ['search_api_db_idx.field'],
      'null_byte'                   => ["search_api_db_idx\x00"],
      'backtick_injection'          => ['search_api_db_`idx`'],
      'quote_injection'             => ["search_api_db_'idx'"],
      'comment_injection'           => ['search_api_db_idx/**/'],
      'parentheses'                 => ['search_api_db_idx()'],
      'newline'                     => ["search_api_db_idx\nUNION SELECT"],
      'slash_traversal'             => ['search_api_db_idx/../../etc'],
      'at_sign'                     => ['search_api_db_idx@server'],
    ];
  }

  /**
   * @dataProvider provideUnprefixedTables
   */
  public function testLayer2RejectsUnprefixedTables(string $table): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/does not start with/i');
    $this->mgr->pubAssertSafeTable($table, 'test');
  }

  public static function provideUnprefixedTables(): array {
    return [
      'users_table'          => ['users'],
      'config_table'         => ['config'],
      'node_table'           => ['node'],
      'key_value'            => ['key_value'],
      'taxonomy_term_parent' => ['taxonomy_term__parent'],
      'watchdog'             => ['watchdog'],
      'semaphore'            => ['semaphore'],
      'partial_prefix'       => ['search_api_'],
      'wrong_prefix'         => ['searchapidb_idx'],
      'prefix_with_extra'    => ['xsearch_api_db_idx'],
      'close_but_no'         => ['search_api_db'],
    ];
  }

  public function testLayer3RejectsNonExistentTable(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/does not exist/i');
    $this->mgr->pubAssertSafeTable('search_api_db_nonexistent', 'test');
  }

  public function testLayer3PassesForExistingTable(): void {
    $this->mgr->pubAssertSafeTable('search_api_db_idx', 'test');
    $this->addToAssertionCount(1);
  }

  public function testLayer4RejectsUnregisteredTable(): void {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $schema->method('fieldExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $mgr = new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/not registered to any Search API index/i');
    $mgr->pubAssertSafeTable('search_api_db_idx', 'test', ['search_api_db_other_table']);
  }

  public function testLayer4PassesForRegisteredTable(): void {
    $this->mgr->pubAssertSafeTable('search_api_db_idx', 'test', $this->existingTables);
    $this->addToAssertionCount(1);
  }

  public function testLayer4SkippedWhenNoWhitelistSupplied(): void {
    $this->mgr->pubAssertSafeTable('search_api_db_idx', 'test', []);
    $this->addToAssertionCount(1);
  }

  public function testTableNameWithTrailingNewlineIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeTable("search_api_db_idx\n", 'test');
  }

  public function testColumnNameWithTrailingNewlineIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeColumn("value\n", 'search_api_db_idx_field_a', 'test', TRUE);
  }

  public function testTableNameExceedingMaxLengthIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeTable('search_api_db_' . str_repeat('a', 200), 'test');
  }

  public function testVeryLongTableNameIsTruncatedInErrorMessage(): void {
    $huge = 'search_api_db_' . str_repeat('a', 5000);
    try {
      $this->mgr->pubAssertSafeTable($huge, 'test');
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $this->assertLessThan(\strlen($huge), \strlen($msg));
      $this->assertStringContainsString('…', $msg);
      $this->assertStringNotContainsString($huge, $msg);
    }
  }

  public function testTableNameWithQuoteIsNotShownRaw(): void {
    $tricky = "bad'name";
    try {
      $this->mgr->pubAssertSafeTable($tricky, 'test');
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $this->assertStringNotContainsString($tricky, $msg);
      $this->assertStringContainsString('badname', $msg);
    }
  }

  public function testTableNameWithNewlineIsNotShownRaw(): void {
    $tricky = "bad\nname";
    try {
      $this->mgr->pubAssertSafeTable($tricky, 'test');
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $this->assertStringNotContainsString("\n", $msg);
      $this->assertStringContainsString('badname', $msg);
    }
  }

  public function testTableNameWithZeroWidthSpaceIsNotShownRaw(): void {
    $tricky = "search_api_db_idx\u{200B}";
    try {
      $this->mgr->pubAssertSafeTable($tricky, 'test');
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $this->assertStringNotContainsString("\u{200B}", $msg);
      $this->assertStringContainsString('search_api_db_idx', $msg);
    }
  }

  public function testCyrillicTableNameIsShownLegiblyNotStripped(): void {
    $cyrillic = "search_api_db_id\u{0430}";
    try {
      $this->mgr->pubAssertSafeTable($cyrillic, 'test');
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $this->assertStringContainsString($cyrillic, $e->getMessage());
    }
  }

  /**
   * Aggregate empties its target first; an index main table must be refused
   * before that, not rescued by the later INSERT failing.
   */
  public function testAggregateRejectsMainTableAsTarget(): void {
    $this->expectExceptionMessageMatches("/'value' does not exist in table 'search_api_db_idx'/");
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx',
      'source_tables' => ['search_api_db_idx_field_a'],
    ], $this->existingTables);
  }

  public function testVeryLongAggregateSourceTableIsTruncatedInErrorMessage(): void {
    $huge = 'search_api_db_' . str_repeat('b', 5000);
    try {
      $this->mgr->validateAggregateOp([
        'target_table'  => 'search_api_db_idx_field_agg',
        'source_tables' => [$huge],
      ], $this->existingTables);
      $this->fail('Expected an OperationValidationException.');
    }
    catch (\Exception $e) {
      $msg = $e->getMessage();
      $this->assertLessThan(\strlen($huge), \strlen($msg));
      $this->assertStringContainsString('…', $msg);
      $this->assertStringNotContainsString($huge, $msg);
    }
  }

  /**
   * @dataProvider provideUnicodeHomoglyphTables
   */
  public function testUnicodeHomoglyphTableNameIsRejected(string $table): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeTable($table, 'test');
  }

  public static function provideUnicodeHomoglyphTables(): array {
    return [
      'cyrillic_a'    => ["search_api_db_id\u{0430}"],
      'fullwidth'     => ["search_api_db_\u{FF41}bc"],
      'zero_width'    => ["search_api_db_idx\u{200B}"],
    ];
  }

  public function testHierarchySourceTableWithTrailingNewlineIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => "taxonomy_term__parent\n",
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
        ],
      ],
    ], $this->existingTables);
  }

  /**
   * @dataProvider provideForbiddenColumns
   */
  public function testForbiddenColumnsAreBlockedOnWrite(string $col): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/protected Search API core column/i');
    $this->mgr->pubAssertSafeColumn($col, 'search_api_db_idx_field_a', 'test', TRUE);
  }

  public static function provideForbiddenColumns(): array {
    return [
      ['item_id'],
      ['search_api_id'],
      ['search_api_datasource'],
      ['search_api_language'],
      ['search_api_relevance'],
      ['search_api_random'],
    ];
  }

  public function testForbiddenColumnAllowedAsReadSource(): void {
    $this->mgr->pubAssertSafeColumn('item_id', 'search_api_db_idx_field_a', 'test', FALSE);
    $this->addToAssertionCount(1);
  }

  /**
   * @dataProvider provideInvalidColumnNamesSyntax
   */
  public function testColumnSyntaxRejectsInjectionAttempts(string $col): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');
    $this->mgr->pubAssertSafeColumn($col, 'search_api_db_idx', 'test', TRUE);
  }

  public static function provideInvalidColumnNamesSyntax(): array {
    return [
      'backtick_injection'     => ['`value`'],
      'sql_comment_injection'  => ['value--'],
      'semicolon_injection'    => ['value;DROP TABLE users'],
      'space'                  => ['field value'],
      'uppercase'              => ['Value'],
      'dot_notation'           => ['t.value'],
      'parentheses'            => ['value()'],
      'quote'                  => ["value'"],
      'null_byte'              => ["value\x00"],
    ];
  }

  public function testColumnNonExistentIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/does not exist/i');
    $this->mgr->pubAssertSafeColumn('nonexistent_col', 'search_api_db_idx', 'test');
  }

  /**
   * @dataProvider provideAllowedAggFuncs
   */
  public function testAllowedAggFunctionsPassValidation(string $func): void {
    $op = [
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [
        ['table' => 'search_api_db_idx_field_a', 'column' => 'value'],
      ],
      'aggregate_func' => $func,
    ];
    $allowed = $this->existingTables;
    $this->mgr->validateFillFromUnionOp($op, $allowed);
    $this->addToAssertionCount(1);
  }

  public static function provideAllowedAggFuncs(): array {
    return [
      ['MIN'],
      ['MAX'],
      ['SUM'],
      ['COUNT'],
      ['AVG'],
    ];
  }

  /**
   * @dataProvider provideBlockedAggFuncs
   */
  public function testBlockedAggFunctionsAreRejected(string $func): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/not allowed/i');

    $op = [
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => $func,
    ];
    $this->mgr->validateFillFromUnionOp($op, $this->existingTables);
  }

  public static function provideBlockedAggFuncs(): array {
    return [
      'UPPER injection'      => ['UPPER'],
      'SLEEP injection'      => ['SLEEP(10)'],
      'NOW injection'        => ['NOW'],
      'LOAD_FILE'            => ['LOAD_FILE'],
      'RAND'                 => ['RAND'],
      'SUBSTRING'            => ['SUBSTRING'],
      'CONCAT injection'     => ['CONCAT(1,2)'],
      'HEX'                  => ['HEX'],
      'empty string'         => [''],
      'DROP TABLE injection' => ["MIN); DROP TABLE users; --"],
      'UNION injection'      => ['UNION SELECT'],
    ];
  }

  public function testGroupConcatAllowedOnMysql(): void {
    $op = [
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'GROUP_CONCAT',
    ];
    $this->mgr->validateFillFromUnionOp($op, $this->existingTables);
    $this->addToAssertionCount(1);
  }

  /**
   * @dataProvider provideSupportedDrivers
   */
  public function testGroupConcatAllowedOnAllSupportedDrivers(string $driver): void {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $schema->method('fieldExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn($driver);

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $mgr = new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );

    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'GROUP_CONCAT',
    ], []);
    $this->addToAssertionCount(1);
  }

  public function provideSupportedDrivers(): array {
    return [
      'mysql'  => ['mysql'],
      'pgsql'  => ['pgsql'],
      'sqlite' => ['sqlite'],
    ];
  }

  public function testAssertSupportedDriverPassesForMysql(): void {
    $this->mgr->assertSupportedDriver();
    $this->addToAssertionCount(1);
  }

  private function mgrWithDriver(string $driver): TestableOperationValidator {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn($driver);

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    return new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );
  }

  public function testAssertSupportedDriverPassesForPgsql(): void {
    $this->mgrWithDriver('pgsql')->assertSupportedDriver();
    $this->addToAssertionCount(1);
  }

  public function testAssertSupportedDriverPassesForSqlite(): void {
    $this->mgrWithDriver('sqlite')->assertSupportedDriver();
    $this->addToAssertionCount(1);
  }

  public function testAssertSupportedDriverRejectsNonRelationalDriverName(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/active driver is 'mongodb', which is not one of them/i");
    $this->mgrWithDriver('mongodb')->assertSupportedDriver();
  }

  /**
   * @dataProvider provideAllowedJoinTypes
   */
  public function testAllowedJoinTypesPassForCopy(string $joinType): void {
    $op = [
      'source_table'  => 'search_api_db_idx_field_a',
      'source_column' => 'value',
      'target_table'  => 'search_api_db_idx',
      'target_column' => 'field_a',
      'join_type'     => $joinType,
    ];
    $this->mgr->validateCopyOp($op, $this->existingTables);
    $this->addToAssertionCount(1);
  }

  public static function provideAllowedJoinTypes(): array {
    return [['INNER'], ['LEFT'], ['inner'], ['left']];
  }

  /**
   * @dataProvider provideBlockedJoinTypes
   */
  public function testBlockedJoinTypesAreRejected(string $joinType): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid join_type/i');

    $op = [
      'source_table'  => 'search_api_db_idx_field_a',
      'source_column' => 'value',
      'target_table'  => 'search_api_db_idx',
      'target_column' => 'field_a',
      'join_type'     => $joinType,
    ];
    $this->mgr->validateCopyOp($op, $this->existingTables);
  }

  public static function provideBlockedJoinTypes(): array {
    return [
      'CROSS'                    => ['CROSS'],
      'RIGHT'                    => ['RIGHT'],
      'FULL'                     => ['FULL'],
      'OUTER'                    => ['OUTER'],
      'injection with semicolon' => ["INNER; DROP TABLE users"],
      'SQL comment'              => ['INNER--'],
      'empty'                    => [''],
    ];
  }

  /**
   * @dataProvider provideAllowedJoinTypes
   */
  public function testJoinTypeAllowlistForFillFromUnion(string $joinType): void {
    $op = [
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'MIN',
      'join_type'      => $joinType,
    ];
    $this->mgr->validateFillFromUnionOp($op, $this->existingTables);
    $this->addToAssertionCount(1);
  }

  /**
   * @dataProvider provideBlockedJoinTypes
   */
  public function testBlockedJoinTypeIsRejectedForFillFromUnion(string $joinType): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid join_type/i');

    $op = [
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'MIN',
      'join_type'      => $joinType,
    ];
    $this->mgr->validateFillFromUnionOp($op, $this->existingTables);
  }

  public function testAggregateSourceEqualsTargetIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/circular reference/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_a',
      'source_tables' => ['search_api_db_idx_field_a'],
    ], $this->existingTables);
  }

  public function testAggregateMainTableEqualsTargetIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/cannot be the same/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'main_table'  => 'search_api_db_idx_field_agg',
        'main_column' => 'value',
      ],
    ], $this->existingTables);
  }

  public function testCopySourceEqualsTargetColumnIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/identical/i');

    $this->mgr->validateCopyOp([
      'source_table'  => 'search_api_db_idx',
      'source_column' => 'field_a',
      'target_table'  => 'search_api_db_idx',
      'target_column' => 'field_a',
    ], $this->existingTables);
  }

  public function testAggregateSourceWithWrongPrefixIsRejected(): void {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $schema->method('fieldExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn('mysql');

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $mgr = new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/does not start with/i');

    $mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['users'],
    ], ['search_api_db_idx_field_agg', 'users']);
  }

  public function testAggregateExceedingMaxSourcesIsRejected(): void {
    $sources = [];
    for ($i = 0; $i <= OperationValidator::MAX_SOURCES; $i++) {
      $sources[] = 'search_api_db_idx_field_a';
    }

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/too many source/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => $sources,
    ], $this->existingTables);
  }

  public function testFillFromUnionExceedingMaxSourcesIsRejected(): void {
    $sources = [];
    for ($i = 0; $i <= OperationValidator::MAX_SOURCES; $i++) {
      $sources[] = ['table' => 'search_api_db_idx_field_a', 'column' => 'value'];
    }

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/too many sources/i');

    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx',
      'target_column'  => 'field_a',
      'sources'        => $sources,
      'aggregate_func' => 'MIN',
    ], $this->existingTables);
  }

  public function testMainTableWithoutMainColumnIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/main_column is.*missing/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => ['main_table' => 'search_api_db_idx', 'main_column' => ''],
    ], $this->existingTables);
  }

  public function testMainColumnWithoutMainTableIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/main_table is.*missing/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => ['main_table' => '', 'main_column' => 'field_a'],
    ], $this->existingTables);
  }

  public function testPriorityFillSourceMissingTableKeyIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/must have 'table' and 'column'/i");

    $this->mgr->validatePriorityFillOp([
      'target_table'  => 'search_api_db_idx',
      'target_column' => 'field_a',
      'sources'       => [['column' => 'value']],
    ], $this->existingTables);
  }

  public function testPriorityFillSourceMissingColumnKeyIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/must have 'table' and 'column'/i");

    $this->mgr->validatePriorityFillOp([
      'target_table'  => 'search_api_db_idx',
      'target_column' => 'field_a',
      'sources'       => [['table' => 'search_api_db_idx_field_a']],
    ], $this->existingTables);
  }

  public function testCustomSqlWithEmptyStringIsRejectedByValidateOperations(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => ''],
    ]);
    $this->assertSame('error', $results[0]['status']);
  }

  public function testCustomSqlWithWhitespaceOnlyIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "   \t\n   "],
    ]);
    $this->assertSame('error', $results[0]['status']);
  }

  public function testCustomSqlWithContentPassesValidation(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "UPDATE {search_api_db_idx} SET field_a = 1 WHERE item_id = 5"],
    ]);
    $this->assertSame('ok', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('whitelist', $results[0]['msg']);
  }

  public function testCustomSqlWriteToNonSearchApiTableIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "DELETE FROM {key_value} WHERE collection = 'test'"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('does not start with', $results[0]['msg']);
  }

  public function testCustomSqlReadFromNonSearchApiTableIsAllowed(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "SELECT value FROM key_value WHERE name = 'test'"],
    ]);
    $this->assertSame('ok', $results[0]['status']);
  }

  public function testCustomSqlUpdateSourcedFromNonSearchApiTableIsAllowed(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "UPDATE {search_api_db_idx} SET field_a = (SELECT value FROM key_value WHERE name = 'x')"],
    ]);
    $this->assertSame('ok', $results[0]['status']);
  }

  public function testCustomSqlMultiTableUpdateJoinIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "UPDATE {search_api_db_idx} JOIN key_value ON 1=1 SET field_a = 1"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('could not confidently identify', $results[0]['msg']);
  }

  public function testCustomSqlEmbeddedSemicolonIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "UPDATE {search_api_db_idx} SET field_a = 1; DROP TABLE {search_api_db_idx}"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('single sql statement', $results[0]['msg']);
  }

  public function testCustomSqlUnsupportedCommandIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "DROP TABLE {search_api_db_idx}"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('unrecognised or unsupported', $results[0]['msg']);
  }

  public function testCustomSqlSelectIntoOutfileIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "SELECT * FROM key_value INTO OUTFILE '/tmp/evil.csv'"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('may not contain into', $results[0]['msg']);
  }

  /**
   * EXPLAIN ANALYZE executes the statement (PostgreSQL; MySQL 8.0.18+).
   *
   * @dataProvider provideExplainAnalyze
   */
  public function testCustomSqlExplainAnalyzeIsRejected(string $sql): void {
    $results = $this->mgr->validateOperations([['type' => 'custom_sql', 'sql' => $sql]]);
    $this->assertSame('error', $results[0]['status']);
  }

  public static function provideExplainAnalyze(): array {
    return [
      'pg delete' => ['EXPLAIN ANALYZE DELETE FROM key_value'],
      'pg options' => ['EXPLAIN (ANALYZE, BUFFERS) UPDATE key_value SET value = 1'],
      'british' => ['EXPLAIN ANALYSE DELETE FROM key_value'],
      'describe' => ['DESCRIBE ANALYZE DELETE key_value FROM key_value'],
    ];
  }

  /**
   * The write target must be the whole table reference, not a prefix of it.
   *
   * @dataProvider provideQualifiedWriteTargets
   */
  public function testCustomSqlQualifiedOrMultiTableWriteTargetIsRejected(string $sql): void {
    $results = $this->mgr->validateOperations([['type' => 'custom_sql', 'sql' => $sql]]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('could not confidently identify', $results[0]['msg']);
  }

  public static function provideQualifiedWriteTargets(): array {
    return [
      'mysql multi-table delete' => ['DELETE FROM search_api_db_idx.*, key_value.* USING search_api_db_idx, key_value'],
      'schema-qualified insert' => ['INSERT INTO search_api_db_idx.key_value (name) VALUES (1)'],
      'schema-qualified delete' => ['DELETE FROM search_api_db_idx.key_value'],
    ];
  }

  public function testCustomSqlWithCteIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql', 'sql' => "WITH cte AS (SELECT 1) SELECT * FROM cte"],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('common table expressions', $results[0]['msg']);
  }

  public function testCustomSqlUnsafeRefusedWhenSubmoduleNotInstalled(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'custom_sql_unsafe', 'sql' => 'DROP TABLE users', 'confirmed' => TRUE],
    ]);
    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase("'Unsafe Custom SQL' submodule", $results[0]['msg']);
    $this->assertStringContainsStringIgnoringCase('is not installed', $results[0]['msg']);
  }

  public function testUnknownOperationTypeIsRejected(): void {
    $results = $this->mgr->validateOperations([
      ['type' => 'evil_op', 'target_table' => 'search_api_db_idx'],
    ]);
    $this->assertSame('error', $results[0]['status']);
  }

  public function testHierarchySourceTableWithSemicolonIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent; DROP TABLE users--',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceEntityColWithSqlCommentIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id--comment',
          'parent_col' => 'parent_target_id',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceParentColWithSpaceIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent target id',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceParentColWithQuoteIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id',
          'parent_col' => "parent' OR '1'='1",
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceTableWithXssPayloadIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/invalid characters/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => '<script>alert(1)</script>',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceNullValueNonNumericIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/null_value must be numeric/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
          'null_value' => '0 OR 1=1',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceNullValueSqlKeywordIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/null_value must be numeric/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
          'null_value' => 'NULL; DROP TABLE users',
        ],
      ],
    ], $this->existingTables);
  }

  public function testHierarchySourceValidConfigIsAccepted(): void {
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_idx_field_agg',
      'source_tables' => ['search_api_db_idx_field_a'],
      'options'       => [
        'hierarchy_source' => [
          'table'      => 'taxonomy_term__parent',
          'entity_col' => 'entity_id',
          'parent_col' => 'parent_target_id',
          'null_value' => 0,
        ],
      ],
    ], $this->existingTables);
    $this->addToAssertionCount(1);
  }

}
