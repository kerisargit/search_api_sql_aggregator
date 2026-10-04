<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 */
class AggregationManagerIndexDropTest extends UnitTestCase {

  use StringTranslationContainerTrait;

  protected function setUp(): void {
    parent::setUp();
    $this->setUpStringTranslationContainer();
  }

  private function makeManager(string $driver, ?Connection $db = NULL): TestableAggregationManager {
    if (!$db) {
      $db = $this->createMock(Connection::class);
      $db->method('driver')->willReturn($driver);
    }

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('integer');
    $field->method('getLabel')->willReturn('Context Mark');
    $field->method('getPropertyPath')->willReturn('field_headword:entity:field_elements:entity:field_context_mark');

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('entry');
    $index->method('label')->willReturn('Entry');
    $index->method('getServerId')->willReturn('default');
    $index->method('getServerInstance')->willThrowException(new \Exception('no server in this test'));
    $index->method('getFields')->willReturn(['context_mark_x' => $field]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn(['entry' => $index]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $validator = $this->createMock(OperationValidator::class);
    $validator->method('getIndexDbInfo')->willReturn([
      'field_tables' => [
        'context_mark_x' => [
          'table'  => 'search_api_db_entry_context_mark_x',
          'column' => 'context_mark_x',
        ],
      ],
    ]);

    return new TestableAggregationManager(
      $db,
      $factory,
      $this->createMock(StateInterface::class),
      $this->createMock(LockBackendInterface::class),
      $entityTypeManager,
      $validator
    );
  }

  public function testResolveDenormalizedColumnForFindsRealFieldTable(): void {
    $mgr = $this->makeManager('mysql');

    $result = $mgr->pubResolveDenormalizedColumnFor('search_api_db_entry_context_mark_x');

    $this->assertSame([
      'denormalized_table' => 'search_api_db_entry',
      'column'             => 'context_mark_x',
      'index_id'           => 'entry',
    ], $result);
  }

  public function testResolveDenormalizedColumnForReturnsNullForUnknownTable(): void {
    $mgr = $this->makeManager('mysql');

    $this->assertNull($mgr->pubResolveDenormalizedColumnFor('search_api_db_entry_no_such_table'));
  }

  public function testNonMysqlDriverSkipsWithoutTouchingSchema(): void {
    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('pgsql');
    $db->expects($this->never())->method('schema');

    $mgr = $this->makeManager('pgsql', $db);

    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $this->assertCount(1, $details);
    $this->assertSame('SKIP', $details[0]['op']);
  }

  public function testDropsIndexWhenItExists(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('indexExists')
      ->with('search_api_db_entry', '_context_mark_x')
      ->willReturn(TRUE);
    $schema->expects($this->once())
      ->method('dropIndex')
      ->with('search_api_db_entry', '_context_mark_x');

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);
    $statement = $this->createMock(\Drupal\Core\Database\StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);
    $db->method('query')->willReturn($statement);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $dropRow = \array_values(\array_filter($details, fn($d) => $d['op'] === 'DROP INDEX'))[0] ?? NULL;
    $this->assertNotNull($dropRow);
    $this->assertStringContainsString('Dropped', $dropRow['rows']);
  }

  public function testDoesNotAttemptDropWhenIndexAlreadyAbsent(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('indexExists')->willReturn(FALSE);
    $schema->expects($this->never())->method('dropIndex');

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);
    $statement = $this->createMock(\Drupal\Core\Database\StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);
    $db->method('query')->willReturn($statement);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $dropRow = \array_values(\array_filter($details, fn($d) => $d['op'] === 'DROP INDEX'))[0] ?? NULL;
    $this->assertNotNull($dropRow);
    $this->assertStringContainsString('Already absent', $dropRow['rows']);
  }

  public function testUnresolvableTableIsSkippedWithoutTouchingSchema(): void {
    $schema = $this->createMock(Schema::class);
    $schema->expects($this->never())->method('indexExists');
    $schema->expects($this->never())->method('dropIndex');

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_totally_unknown']);

    $this->assertCount(1, $details);
    $this->assertSame('SKIP', $details[0]['op']);
  }

  public function testDropFailureIsCaughtAndReportedNotThrown(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('indexExists')->willReturn(TRUE);
    $schema->method('dropIndex')->willThrowException(new \RuntimeException('lock wait timeout'));

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);
    $statement = $this->createMock(\Drupal\Core\Database\StatementInterface::class);
    $statement->method('fetchField')->willReturn(1);
    $db->method('query')->willReturn($statement);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $dropRow = \array_values(\array_filter($details, fn($d) => $d['op'] === 'DROP INDEX'))[0] ?? NULL;
    $this->assertNotNull($dropRow);
    $this->assertStringContainsString('Failed', $dropRow['rows']);
  }

  public function testBudgetWarningAppearsOnceThresholdReached(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('indexExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);
    $statement = $this->createMock(\Drupal\Core\Database\StatementInterface::class);
    $statement->method('fetchField')->willReturn(60);
    $db->method('query')->willReturn($statement);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $warningRow = \array_values(\array_filter($details, fn($d) => $d['op'] === 'WARNING'))[0] ?? NULL;
    $this->assertNotNull($warningRow);
    $this->assertStringContainsString('60', $warningRow['rows']);
  }

  public function testNoBudgetWarningBelowThreshold(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('indexExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('schema')->willReturn($schema);
    $statement = $this->createMock(\Drupal\Core\Database\StatementInterface::class);
    $statement->method('fetchField')->willReturn(10);
    $db->method('query')->willReturn($statement);

    $mgr = $this->makeManager('mysql', $db);
    $details = $mgr->pubDropSourceIndexesIfRequested(['search_api_db_entry_context_mark_x']);

    $warningRow = \array_values(\array_filter($details, fn($d) => $d['op'] === 'WARNING'))[0] ?? NULL;
    $this->assertNull($warningRow);
  }

}
