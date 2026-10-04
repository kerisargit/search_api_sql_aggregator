<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_sql_aggregator\Exception\OperationValidationException;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 * @group validation
 */
class AggregationManagerValidationTest extends UnitTestCase {

  use StringTranslationContainerTrait;

  private TestableOperationValidator $mgr;

  private array $tables = [
    'search_api_db_entry',
    'search_api_db_entry_field_a',
    'search_api_db_entry_field_b',
    'search_api_db_entry_field_c',
    'search_api_db_entry_field_agg',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->setUpStringTranslationContainer();

    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')
      ->willReturnCallback(fn(string $t) => in_array($t, $this->tables, TRUE));
    $schema->method('fieldExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn('mysql');

    $logger  = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $this->mgr = new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );
    $this->mgr->setSearchApiTables($this->tables);
  }

  public function testAggregateValidMinimalConfig(): void {
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a', 'search_api_db_entry_field_b'],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testAggregateValidWithMainTableAndColumn(): void {
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a'],
      'options'       => [
        'main_table'  => 'search_api_db_entry',
        'main_column' => 'field_a',
      ],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testAggregateMissingTargetTableIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/target_table is required/i');

    $this->mgr->validateAggregateOp([
      'source_tables' => ['search_api_db_entry_field_a'],
    ], $this->tables);
  }

  public function testAggregateEmptySourceTablesIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/non-empty array/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => [],
    ], $this->tables);
  }

  public function testAggregateNonStringSourceIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/non-empty string/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => [42],
    ], $this->tables);
  }

  public function testAggregateSourcesAtExactLimitIsAccepted(): void {
    $sources = array_fill(0, 50, 'search_api_db_entry_field_a');
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => $sources,
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testAggregateDropIndexForSourcesAcceptsSubsetOfOwnSources(): void {
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a', 'search_api_db_entry_field_b'],
      'options'       => [
        'drop_index_for_sources' => ['search_api_db_entry_field_a'],
      ],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testAggregateDropIndexForSourcesAcceptsEmptyList(): void {
    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a'],
      'options'       => ['drop_index_for_sources' => []],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testAggregateDropIndexForSourcesRejectsNonArray(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/drop_index_for_sources must be an array/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a'],
      'options'       => ['drop_index_for_sources' => 'search_api_db_entry_field_a'],
    ], $this->tables);
  }

  public function testAggregateDropIndexForSourcesRejectsNonStringEntry(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/non-empty string/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a'],
      'options'       => ['drop_index_for_sources' => [42]],
    ], $this->tables);
  }

  public function testAggregateDropIndexForSourcesRejectsTableNotInOwnSources(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/is not one of this operation.s own source_tables/i');

    $this->mgr->validateAggregateOp([
      'target_table'  => 'search_api_db_entry_field_agg',
      'source_tables' => ['search_api_db_entry_field_a'],
      'options'       => ['drop_index_for_sources' => ['search_api_db_entry_field_b']],
    ], $this->tables);
  }

  public function testCopyValidConfig(): void {
    $this->mgr->validateCopyOp([
      'source_table'  => 'search_api_db_entry_field_a',
      'source_column' => 'value',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_b',
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testCopyMissingAnyRequiredFieldIsRejected(): void {
    $base = [
      'source_table'  => 'search_api_db_entry_field_a',
      'source_column' => 'value',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_b',
    ];
    foreach (array_keys($base) as $field) {
      $op = $base;
      unset($op[$field]);
      try {
        $this->mgr->validateCopyOp($op, $this->tables);
        $this->fail("Expected exception when '$field' is missing.");
      }
      catch (\Exception) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testCopyWithExplicitLeftJoinIsAccepted(): void {
    $this->mgr->validateCopyOp([
      'source_table'  => 'search_api_db_entry_field_a',
      'source_column' => 'value',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_b',
      'join_type'     => 'LEFT',
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testNullResetValidConfig(): void {
    $this->mgr->validateNullResetOp([
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testNullResetMissingColumnIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->mgr->validateNullResetOp([
      'target_table' => 'search_api_db_entry',
    ], $this->tables);
  }

  public function testNullResetMissingTableIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->mgr->validateNullResetOp([
      'target_column' => 'field_a',
    ], $this->tables);
  }

  public function testFillFromUnionValidConfig(): void {
    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_entry',
      'target_column'  => 'field_a',
      'sources'        => [
        ['table' => 'search_api_db_entry_field_a', 'column' => 'value'],
        ['table' => 'search_api_db_entry_field_b', 'column' => 'value'],
      ],
      'aggregate_func' => 'MIN',
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testFillFromUnionEmptySourcesRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/non-empty array/i');

    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_entry',
      'target_column'  => 'field_a',
      'sources'        => [],
      'aggregate_func' => 'MIN',
    ], $this->tables);
  }

  public function testFillFromUnionSourceMissingTableKeyIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/must have 'table' and 'column'/i");

    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_entry',
      'target_column'  => 'field_a',
      'sources'        => [['column' => 'value']],
      'aggregate_func' => 'MIN',
    ], $this->tables);
  }

  public function testFillFromUnionSourceMissingColumnKeyIsRejected(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/must have 'table' and 'column'/i");

    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_entry',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_entry_field_a']],
      'aggregate_func' => 'MIN',
    ], $this->tables);
  }

  public function testFillFromUnionDefaultsToMinWhenFuncMissing(): void {
    $this->mgr->validateFillFromUnionOp([
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
      'sources'       => [['table' => 'search_api_db_entry_field_a', 'column' => 'value']],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  private function makeValidatorWithIndexFields(
    string $indexId,
    array $fieldTypes,
    ?\Drupal\search_api\DataType\DataTypePluginManager $dataTypePluginManager = NULL
  ): TestableOperationValidator {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);
    $schema->method('fieldExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn('mysql');

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $fields = [];
    $fieldTableInfo = [];
    foreach ($fieldTypes as $fieldId => $type) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($type);
      $fields[$fieldId] = $field;
      $fieldTableInfo[$fieldId] = ['table' => 'search_api_db_' . $indexId . '_' . $fieldId, 'column' => 'value'];
    }

    $serverId = 'test_server';
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn($indexId);
    $index->method('getFields')->willReturn($fields);
    $index->method('getServerId')->willReturn($serverId);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$index]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $keyValueStore = $this->createMock(KeyValueStoreInterface::class);
    $keyValueStore->method('get')->with($indexId, [])->willReturn([
      'server' => $serverId,
      'field_tables' => $fieldTableInfo,
    ]);
    $keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $keyValueFactory->method('get')->with('search_api_db.indexes')->willReturn($keyValueStore);

    return new TestableOperationValidator($db, $factory, $entityTypeManager, $keyValueFactory, $dataTypePluginManager);
  }

  private function allowedTablesFor(string $indexId, array $fieldIds): array {
    $tables = ['search_api_db_' . $indexId];
    foreach ($fieldIds as $fieldId) {
      $tables[] = 'search_api_db_' . $indexId . '_' . $fieldId;
      $tables[] = 'search_api_db_' . $indexId . '_' . $fieldId . '_aggregated';
    }
    return $tables;
  }

  public function testFillFromUnionSumRejectsTextTypedSource(): void {
    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'string']);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/Search API type 'string'/");
    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'SUM',
    ], $this->allowedTablesFor('idx', ['field_a']));
  }

  public function testFillFromUnionAvgRejectsStringTypedSource(): void {
    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'string']);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches("/Search API type 'string'/");
    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'AVG',
    ], $this->allowedTablesFor('idx', ['field_a']));
  }

  public function testFillFromUnionSumAllowsIntegerTypedSource(): void {
    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'integer']);

    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'SUM',
    ], $this->allowedTablesFor('idx', ['field_a']));
    $this->addToAssertionCount(1);
  }

  public function testFillFromUnionSumAllowsDecimalTypedSource(): void {
    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'decimal']);

    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'AVG',
    ], $this->allowedTablesFor('idx', ['field_a']));
    $this->addToAssertionCount(1);
  }

  public function testFillFromUnionMinAllowsTextTypedSource(): void {
    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'string']);

    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'MIN',
    ], $this->allowedTablesFor('idx', ['field_a']));
    $this->addToAssertionCount(1);
  }

  public function testFillFromUnionSumAllowsCustomTypeThatFallsBackToNumeric(): void {
    $customDataType = $this->createMock(\Drupal\search_api\DataType\DataTypeInterface::class);
    $customDataType->method('isDefault')->willReturn(FALSE);
    $customDataType->method('getFallbackType')->willReturn('decimal');

    $decimalDataType = $this->createMock(\Drupal\search_api\DataType\DataTypeInterface::class);
    $decimalDataType->method('isDefault')->willReturn(TRUE);

    $dataTypePluginManager = $this->createMock(\Drupal\search_api\DataType\DataTypePluginManager::class);
    $dataTypePluginManager->method('createInstance')->willReturnCallback(
      fn(string $pluginId) => $pluginId === 'price' ? $customDataType : $decimalDataType
    );

    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'price'], $dataTypePluginManager);

    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'SUM',
    ], $this->allowedTablesFor('idx', ['field_a']));
    $this->addToAssertionCount(1);
  }

  public function testFillFromUnionSumRejectsUnknownCustomTypeWhenPluginLookupFails(): void {
    $dataTypePluginManager = $this->createMock(\Drupal\search_api\DataType\DataTypePluginManager::class);
    $dataTypePluginManager->method('createInstance')
      ->willThrowException(new \Exception('unknown plugin ID'));

    $mgr = $this->makeValidatorWithIndexFields('idx', ['field_a' => 'nonexistent_type'], $dataTypePluginManager);

    $this->expectException(OperationValidationException::class);
    $this->expectExceptionMessageMatches("/type 'nonexistent_type', which is stored as text/");
    $mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_idx_field_a_aggregated',
      'target_column'  => 'value',
      'sources'        => [['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
      'aggregate_func' => 'SUM',
    ], $this->allowedTablesFor('idx', ['field_a']));
  }

  public function testFillFromUnionSumAllowsUnresolvableSourceType(): void {
    $this->mgr->validateFillFromUnionOp([
      'target_table'   => 'search_api_db_entry',
      'target_column'  => 'field_a',
      'sources'        => [['table' => 'search_api_db_entry_field_a', 'column' => 'value']],
      'aggregate_func' => 'SUM',
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testPriorityFillValidConfig(): void {
    $this->mgr->validatePriorityFillOp([
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
      'sources'       => [
        ['table' => 'search_api_db_entry_field_a', 'column' => 'value'],
        ['table' => 'search_api_db_entry_field_b', 'column' => 'value'],
      ],
    ], $this->tables);
    $this->addToAssertionCount(1);
  }

  public function testPriorityFillEmptySourcesRejected(): void {
    $this->expectException(\Exception::class);
    $this->mgr->validatePriorityFillOp([
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
      'sources'       => [],
    ], $this->tables);
  }

  public function testValidateOperationsReturnsResultForEachOp(): void {
    $blocks = [
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_a',
      ],
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_b',
      ],
    ];
    $results = $this->mgr->validateOperations($blocks);
    $this->assertCount(2, $results);
  }

  public function testValidateOperationsResultHasRequiredKeys(): void {
    $results = $this->mgr->validateOperations([
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_a',
      ],
    ]);
    $this->assertArrayHasKey('status', $results[0]);
    $this->assertArrayHasKey('target', $results[0]);
    $this->assertArrayHasKey('msg',    $results[0]);
  }

  public function testValidateOperationsStatusIsOkOrError(): void {
    $results = $this->mgr->validateOperations([
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_a',
      ],
    ]);
    $this->assertSame('ok', $results[0]['status']);

    $results = $this->mgr->validateOperations([
      ['type' => 'null_reset'],
    ]);
    $this->assertSame('error', $results[0]['status']);
  }

  public function testValidateOperationsMixedOpsAllReported(): void {
    $results = $this->mgr->validateOperations([
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_a',
      ],
      ['type' => 'copy'],
      [
        'type' => 'custom_sql',
        'sql'  => 'SELECT 1',
      ],
    ]);

    $this->assertCount(3, $results);
    $this->assertSame('ok',    $results[0]['status']);
    $this->assertSame('error', $results[1]['status']);
    $this->assertSame('ok',    $results[2]['status']);
  }

  public function testValidateOperationsReturnsEmptyArrayForNoBlocks(): void {
    $this->assertSame([], $this->mgr->validateOperations([]));
  }

  public function testValidateOperationsRejectsOverMaxOperations(): void {
    $blocks = array_fill(0, OperationValidator::MAX_OPERATIONS + 1, [
      'type'          => 'null_reset',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
    ]);

    $results = $this->mgr->validateOperations($blocks);

    $this->assertCount(1, $results);
    $this->assertSame('error', $results[0]['status']);
    $this->assertMatchesRegularExpression('/too many operations/i', $results[0]['msg']);
  }

  public function testValidateOperationsRejectsUnsupportedDriver(): void {
    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);
    $db->method('driver')->willReturn('oracle');

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $mgr = new TestableOperationValidator(
      $db, $factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(KeyValueFactoryInterface::class)
    );

    $results = $mgr->validateOperations([[
      'type'          => 'null_reset',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
    ]]);

    $this->assertCount(1, $results);
    $this->assertSame('error', $results[0]['status']);
    $this->assertMatchesRegularExpression("/'oracle'/", $results[0]['msg']);
  }

  public function testEnsureOptionsAddsMissingKey(): void {
    $op = $this->mgr->ensureOptions([
      'type'          => 'null_reset',
      'target_table'  => 'search_api_db_entry',
      'target_column' => 'field_a',
    ]);
    $this->assertArrayHasKey('options', $op);
    $this->assertSame([], $op['options']);
  }

  public function testEnsureOptionsPreservesExistingKey(): void {
    $op = $this->mgr->ensureOptions([
      'type'    => 'aggregate',
      'options' => ['main_table' => 'search_api_db_entry'],
    ]);
    $this->assertSame(['main_table' => 'search_api_db_entry'], $op['options']);
  }

  public function testMaxOperationsConstantIsDefinedAndPositive(): void {
    $this->assertGreaterThan(0, OperationValidator::MAX_OPERATIONS);
    $this->assertGreaterThan(0, OperationValidator::MAX_SOURCES);
  }

  public function testStructureAcceptsWellFormedBlocks(): void {
    $errors = $this->mgr->validateStructure([
      ['type' => 'null_reset', 'target_table' => 'search_api_db_entry', 'target_column' => 'field_a'],
      ['type' => 'custom_sql', 'sql' => 'SELECT 1'],
    ]);
    $this->assertSame([], $errors);
  }

  public function testStructureRejectsNonObjectBlock(): void {
    $errors = $this->mgr->validateStructure([42]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression('/must be a JSON object/i', $errors[0]['msg']);
  }

  public function testStructureRejectsMissingOrNonStringType(): void {
    $errors = $this->mgr->validateStructure([
      ['target_table' => 'search_api_db_entry'],
      ['type' => 123, 'target_table' => 'x'],
    ]);
    $this->assertCount(2, $errors);
    $this->assertMatchesRegularExpression("/'type' must be a non-empty string/i", $errors[0]['msg']);
  }

  public function testStructureRejectsNonStringTableName(): void {
    $errors = $this->mgr->validateStructure([
      ['type' => 'null_reset', 'target_table' => 12345, 'target_column' => 'field_a'],
    ]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression("/'target_table' must be a string, got integer/i", $errors[0]['msg']);
  }

  public function testStructureRejectsSourceTablesThatIsAnObject(): void {
    $errors = $this->mgr->validateStructure([
      ['type' => 'aggregate', 'target_table' => 'search_api_db_entry_field_agg', 'source_tables' => ['a' => 'x']],
    ]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression("/'source_tables' must be a JSON array/i", $errors[0]['msg']);
  }

  public function testStructureRejectsOptionsThatIsAList(): void {
    $errors = $this->mgr->validateStructure([
      [
        'type'          => 'aggregate',
        'target_table'  => 'search_api_db_entry_field_agg',
        'source_tables' => ['search_api_db_entry_field_a'],
        'options'       => ['x', 'y'],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression("/'options' must be a JSON object/i", $errors[0]['msg']);
  }

  public function testStructureRejectsNonStringSourceColumnInFillUnion(): void {
    $errors = $this->mgr->validateStructure([
      [
        'type'          => 'fill_from_union',
        'target_table'  => 'search_api_db_entry',
        'target_column' => 'field_a',
        'sources'       => [['table' => 'search_api_db_entry_field_a', 'column' => ['nested']]],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression("/sources\[0\]\.column' must be a string/i", $errors[0]['msg']);
  }

  public function testStructureRejectsNonStringCustomSql(): void {
    $errors = $this->mgr->validateStructure([
      ['type' => 'custom_sql', 'sql' => ['not', 'a', 'string']],
    ]);
    $this->assertCount(1, $errors);
    $this->assertMatchesRegularExpression("/'sql' must be a string/i", $errors[0]['msg']);
  }

  private function makeServer(string $backendId, string $database): ServerInterface {
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendId')->willReturn($backendId);
    $server->method('getBackendConfig')->willReturn(['database' => $database]);
    return $server;
  }

  /**
   * @param array<string, array{0: string, 1: string}> $indexes
   *   Index id => [backend id, database setting].
   */
  private function makePreflightValidator(array $indexes, array $existingTables): OperationValidator {
    $entities = [];
    foreach ($indexes as $id => [$backend, $database]) {
      $index = $this->createMock(IndexInterface::class);
      $index->method('id')->willReturn($id);
      $index->method('getServerInstance')->willReturn($this->makeServer($backend, $database));
      $entities[$id] = $index;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($entities);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $schema = $this->createMock(\Drupal\Core\Database\Schema::class);
    $schema->method('tableExists')->willReturnCallback(fn(string $t) => \in_array($t, $existingTables, TRUE));
    $db = $this->createMock(Connection::class);
    $db->method('getKey')->willReturn('default');
    $db->method('schema')->willReturn($schema);

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return new OperationValidator($db, $factory, $entityTypeManager, $this->createMock(KeyValueFactoryInterface::class));
  }

  /**
   * @dataProvider providePreflightRefusals
   */
  public function testPreflightRefusesWithOneClearReason(array $indexes, array $tables, string $expected): void {
    $this->expectException(OperationValidationException::class);
    $this->expectExceptionMessage($expected);
    $this->makePreflightValidator($indexes, $tables)->assertSqlIndexesAvailable();
  }

  public static function providePreflightRefusals(): array {
    return [
      'no indexes' => [[], [], 'No Search API index uses the Database backend'],
      'elasticsearch only' => [['es' => ['elasticsearch', '']], [], 'No Search API index uses the Database backend'],
      'other connection' => [['ext' => ['search_api_db', 'external:default']], ['search_api_db_ext'], 'another database connection'],
      'tables missing' => [['idx' => ['search_api_db', 'default:default']], [], "has its tables in Drupal's database"],
    ];
  }

  public function testPreflightPassesAndWhitelistSkipsNonSqlIndexes(): void {
    $validator = $this->makePreflightValidator([
      'idx' => ['search_api_db', 'default:default'],
      'es' => ['elasticsearch', ''],
      'ext' => ['search_api_db', 'external:default'],
    ], ['search_api_db_idx']);

    $validator->assertSqlIndexesAvailable();
    $this->assertSame(['idx'], \array_keys($validator->getSqlIndexes()));
  }

  public function testGetAllSearchApiTablesUsesRealFieldTableNameNotFormula(): void {
    $realTableName    = 'search_api_db_entry_field_headword_collocation_a_9f8e7d6c';
    $naiveFormulaName = 'search_api_db_entry_field_headword_collocation_attributes_author_mark';

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('string');

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('entry');
    $index->method('getServerId')->willReturn('srv');
    $index->method('getServerInstance')->willReturn($this->makeServer('search_api_db', 'default:default'));
    $index->method('getFields')->willReturn([
      'field_headword_collocation_attributes_author_mark' => $field,
    ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$index]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $keyValueStore = $this->createMock(KeyValueStoreInterface::class);
    $keyValueStore->method('get')->with('entry', [])->willReturn([
      'server' => 'srv',
      'field_tables' => [
        'field_headword_collocation_attributes_author_mark' => ['table' => $realTableName, 'column' => 'value'],
      ],
    ]);
    $keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $keyValueFactory->method('get')->with('search_api_db.indexes')->willReturn($keyValueStore);

    $db = $this->createMock(Connection::class);
    $db->method('getKey')->willReturn('default');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $validator = new OperationValidator($db, $factory, $entityTypeManager, $keyValueFactory);
    $tables = $validator->getAllSearchApiTables();

    $this->assertContains($realTableName, $tables, 'The real, search_api_db-reported table name must be in the whitelist.');
    $this->assertNotContains($naiveFormulaName, $tables, 'The old naive-formula guess must NOT be in the whitelist once it disagrees with reality.');
  }

  public function testGetAllSearchApiTablesOmitsFieldWithNoRealTableYet(): void {
    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('string');

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('entry');
    $index->method('getServerId')->willReturn('srv');
    $index->method('getServerInstance')->willReturn($this->makeServer('search_api_db', 'default:default'));
    $index->method('getFields')->willReturn(['field_x' => $field]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$index]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $keyValueStore = $this->createMock(KeyValueStoreInterface::class);
    $keyValueStore->method('get')->with('entry', [])->willReturn(['server' => 'srv', 'field_tables' => []]);
    $keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $keyValueFactory->method('get')->with('search_api_db.indexes')->willReturn($keyValueStore);

    $db = $this->createMock(Connection::class);
    $db->method('getKey')->willReturn('default');
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $validator = new OperationValidator($db, $factory, $entityTypeManager, $keyValueFactory);
    $tables = $validator->getAllSearchApiTables();

    $this->assertContains('search_api_db_entry', $tables);
    $this->assertNotContains('search_api_db_entry_field_x', $tables);
  }

  public function testGetIndexDbInfoIgnoresStaleEntryFromDifferentServer(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('entry');
    $index->method('getServerId')->willReturn('new_server');

    $keyValueStore = $this->createMock(KeyValueStoreInterface::class);
    $keyValueStore->method('get')->with('entry', [])->willReturn([
      'server' => 'old_server',
      'field_tables' => ['field_x' => ['table' => 'search_api_db_entry_field_x', 'column' => 'value']],
    ]);
    $keyValueFactory = $this->createMock(KeyValueFactoryInterface::class);
    $keyValueFactory->method('get')->with('search_api_db.indexes')->willReturn($keyValueStore);

    $db = $this->createMock(Connection::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $validator = new OperationValidator($db, $factory, $entityTypeManager, $keyValueFactory);

    $this->assertSame([], $validator->getIndexDbInfo($index));
  }

}
