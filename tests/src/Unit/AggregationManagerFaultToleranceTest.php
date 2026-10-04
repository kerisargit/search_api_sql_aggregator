<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Drupal\Tests\UnitTestCase;

class TestableAggregationManager extends AggregationManager {

  public function pubExecuteSafeSql(string $sql): int {
    return $this->executeSafeSql($sql);
  }

  public function pubGetUpdateSql(bool $hier, string $main, string $escapedCol, string $union, array $hierSrc = []): string {
    return $this->sql->getUpdateSql($hier, $main, $escapedCol, $union, $hierSrc);
  }

  public function pubGetCopySql(string $srcTable, string $srcCol, string $tgtTable, string $tgtCol, string $joinType, bool $coalesce): string {
    return $this->sql->getCopySql($srcTable, $srcCol, $tgtTable, $tgtCol, $joinType, $coalesce);
  }

  public function pubGetFillFromUnionSql(string $tgtTable, string $escTgt, array $unionParts, string $aggFunc, string $joinType): string {
    return $this->sql->getFillFromUnionSql($tgtTable, $escTgt, $unionParts, $aggFunc, $joinType);
  }

  public function pubGetPriorityFillSql(string $tgtTable, string $escTgt, array $sources): string {
    return $this->sql->getPriorityFillSql($tgtTable, $escTgt, $sources);
  }

  public function pubSetSqlTimeout(int $ms) {
    return $this->sessionTimeout->set($ms);
  }

  public function pubResetSqlTimeout($original): void {
    $this->sessionTimeout->reset($original);
  }

  public function pubDropSourceIndexesIfRequested(array $tables): array {
    return $this->dropSourceIndexesIfRequested($tables);
  }

  public function pubResolveDenormalizedColumnFor(string $sourceTable): ?array {
    return $this->resolveDenormalizedColumnFor($sourceTable);
  }
}

/**
 * @group search_api_sql_aggregator
 * @group fault_tolerance
 */
class AggregationManagerFaultToleranceTest extends UnitTestCase {

  use StringTranslationContainerTrait;

  protected function setUp(): void {
    parent::setUp();
    $this->setUpStringTranslationContainer();
  }

  private function makeManager(Connection $db): TestableAggregationManager {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    return new TestableAggregationManager(
      $db,
      $factory,
      $this->createMock(StateInterface::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(OperationValidator::class)
    );
  }

  public function testReturnsRowCountFromPrepareStatement(): void {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('rowCount')->willReturn(5);

    $db = $this->createMock(Connection::class);
    $db->method('prepareStatement')->willReturn($stmt);

    $mgr = $this->makeManager($db);
    $this->assertSame(5, $mgr->pubExecuteSafeSql('UPDATE {t} SET c = 1'));
  }

  public function testFallsBackToQueryWhenPrepareUnsupported(): void {
    $result = $this->createMock(StatementInterface::class);
    $result->method('rowCount')->willReturn(3);

    $db = $this->createMock(Connection::class);
    $db->method('prepareStatement')->willThrowException(new \Exception('allow_row_count unsupported'));
    $db->method('query')->willReturn($result);

    $mgr = $this->makeManager($db);
    $this->assertSame(3, $mgr->pubExecuteSafeSql('UPDATE {t} SET c = 1'));
  }

  public function testExecuteErrorIsNotRetriedThroughQuery(): void {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('execute')->willThrowException(new \Exception('SQLSTATE[40001]: deadlock found'));

    $db = $this->createMock(Connection::class);
    $db->method('prepareStatement')->willReturn($stmt);
    $db->expects($this->never())->method('query');

    $this->expectExceptionMessage('deadlock found');
    $this->makeManager($db)->pubExecuteSafeSql('UPDATE {t} SET c = 1');
  }

  public function testGenuineSqlErrorPropagatesNotSwallowed(): void {
    $db = $this->createMock(Connection::class);
    $db->method('prepareStatement')->willThrowException(new \Exception('allow_row_count unsupported'));
    $db->method('query')->willThrowException(new \Exception('SQLSTATE[23000]: integrity constraint violation'));

    $mgr = $this->makeManager($db);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/integrity constraint violation/i');
    $mgr->pubExecuteSafeSql('UPDATE {t} SET c = NULL');
  }

  public function testSetSqlTimeoutCapturesAndAppliesOnMysql(): void {
    $queries = [];
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn('0');

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('query')->willReturnCallback(function (string $sql) use (&$queries, $stmt) {
      $queries[] = $sql;
      return $stmt;
    });

    $mgr = $this->makeManager($db);
    $original = $mgr->pubSetSqlTimeout(30000);

    $this->assertSame('0', $original);
    $this->assertCount(2, $queries);
    $this->assertStringContainsString('SELECT @@SESSION.max_execution_time', $queries[0]);
    $this->assertStringContainsString('SET SESSION max_execution_time = 30000', $queries[1]);
  }

  public function testResetSqlTimeoutRestoresOriginalOnMysql(): void {
    $queries = [];
    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('query')->willReturnCallback(function (string $sql) use (&$queries) {
      $queries[] = $sql;
      return $this->createMock(StatementInterface::class);
    });

    $this->makeManager($db)->pubResetSqlTimeout('12345');

    $this->assertCount(1, $queries);
    $this->assertStringContainsString('SET SESSION max_execution_time = 12345', $queries[0]);
  }

  public function testSetAndResetSqlTimeoutUseMaxStatementTimeOnMariaDb(): void {
    $queries = [];
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn('0.000000');

    $db = $this->createMock(\Drupal\mysql\Driver\Database\mysql\Connection::class);
    $db->method('driver')->willReturn('mysql');
    $db->method('isMariaDb')->willReturn(TRUE);
    $db->method('query')->willReturnCallback(function (string $sql) use (&$queries, $stmt) {
      $queries[] = $sql;
      return $stmt;
    });

    $mgr = $this->makeManager($db);
    $original = $mgr->pubSetSqlTimeout(30000);
    $mgr->pubResetSqlTimeout($original);

    $this->assertSame('0.000000', $original);
    $this->assertSame([
      'SELECT @@SESSION.max_statement_time',
      'SET SESSION max_statement_time = 30',
      'SET SESSION max_statement_time = 0',
    ], $queries);
  }

  public function testSetSqlTimeoutUsesSessionScopeNotLocalOnPgsql(): void {
    $queries = [];
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn('0');

    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('pgsql');
    $db->method('query')->willReturnCallback(function (string $sql) use (&$queries, $stmt) {
      $queries[] = $sql;
      return $stmt;
    });

    $this->makeManager($db)->pubSetSqlTimeout(30000);

    $this->assertStringContainsString('SHOW statement_timeout', $queries[0]);
    $this->assertStringContainsString('SET SESSION statement_timeout = 30000', $queries[1]);
    $this->assertStringNotContainsString('LOCAL', $queries[1]);
  }

  public function testResetSqlTimeoutRestoresOriginalOnPgsql(): void {
    $queries = [];
    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('pgsql');
    $db->method('query')->willReturnCallback(function (string $sql) use (&$queries) {
      $queries[] = $sql;
      return $this->createMock(StatementInterface::class);
    });

    $this->makeManager($db)->pubResetSqlTimeout('30s');

    $this->assertCount(1, $queries);
    $this->assertStringContainsString("SET SESSION statement_timeout = '30s'", $queries[0]);
  }

  public function testResetSqlTimeoutIsNoOpWhenOriginalIsNull(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('query');

    $this->makeManager($db)->pubResetSqlTimeout(NULL);
    $this->addToAssertionCount(1);
  }

  public function testSetSqlTimeoutIsNoOpForZeroOrNegativeMs(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('query');

    $mgr = $this->makeManager($db);
    $this->assertNull($mgr->pubSetSqlTimeout(0));
    $this->assertNull($mgr->pubSetSqlTimeout(-5));
  }

  public function testSetSqlTimeoutIsNoOpForUnsupportedDriver(): void {
    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn('sqlite');
    $db->expects($this->never())->method('query');

    $this->assertNull($this->makeManager($db)->pubSetSqlTimeout(30000));
  }

}
