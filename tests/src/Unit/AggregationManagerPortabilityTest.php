<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 * @group portability
 */
class AggregationManagerPortabilityTest extends UnitTestCase {

  use StringTranslationContainerTrait;

  protected function setUp(): void {
    parent::setUp();
    $this->setUpStringTranslationContainer();
  }

  private function makeManager(string $driver): TestableAggregationManager {
    $db = $this->createMock(Connection::class);
    $db->method('driver')->willReturn($driver);
    $db->method('escapeField')->willReturnArgument(0);

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

  public function testUpdateSqlMysqlUsesJoin(): void {
    $sql = $this->makeManager('mysql')->pubGetUpdateSql(FALSE, 'main_t', 'col', 'SELECT 1');
    $this->assertStringContainsString('JOIN (', $sql);
    $this->assertStringContainsString('SET main.col = src.val', $sql);
  }

  public function testUpdateSqlPgsqlUsesFromWhere(): void {
    $sql = $this->makeManager('pgsql')->pubGetUpdateSql(FALSE, 'main_t', 'col', 'SELECT 1');
    $this->assertStringContainsString('SET col = src.val', $sql);
    $this->assertStringContainsString('FROM (', $sql);
    $this->assertStringContainsString('WHERE src.item_id = main.item_id', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testUpdateSqlSqliteUsesFromWhere(): void {
    $sql = $this->makeManager('sqlite')->pubGetUpdateSql(FALSE, 'main_t', 'col', 'SELECT 1');
    $this->assertStringContainsString('FROM (', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testUpdateSqlHierarchyUsesUnsignedCastOnMysql(): void {
    $sql = $this->makeManager('mysql')->pubGetUpdateSql(TRUE, 'main_t', 'col', 'SELECT 1', []);
    $this->assertStringContainsString('AS UNSIGNED)', $sql);
  }

  public function testUpdateSqlHierarchyUsesIntegerCastOnPgsql(): void {
    $sql = $this->makeManager('pgsql')->pubGetUpdateSql(TRUE, 'main_t', 'col', 'SELECT 1', []);
    $this->assertStringContainsString('AS INTEGER)', $sql);
    $this->assertStringNotContainsString('AS UNSIGNED)', $sql);
  }

  public function testUpdateSqlHierarchyUsesIntegerCastOnSqlite(): void {
    $sql = $this->makeManager('sqlite')->pubGetUpdateSql(TRUE, 'main_t', 'col', 'SELECT 1', []);
    $this->assertStringContainsString('AS INTEGER)', $sql);
  }

  public function testCopySqlMysqlInnerUsesJoin(): void {
    $sql = $this->makeManager('mysql')->pubGetCopySql('src_t', 'src_c', 'tgt_t', 'tgt_c', 'INNER', FALSE);
    $this->assertStringContainsString('INNER JOIN', $sql);
    $this->assertStringContainsString('SET t.tgt_c = s.src_c', $sql);
  }

  public function testCopySqlPgsqlInnerUsesFromWhere(): void {
    $sql = $this->makeManager('pgsql')->pubGetCopySql('src_t', 'src_c', 'tgt_t', 'tgt_c', 'INNER', FALSE);
    $this->assertStringContainsString('SET tgt_c = s.src_c', $sql);
    $this->assertStringContainsString('FROM {src_t} s', $sql);
    $this->assertStringContainsString('WHERE s.item_id = t.item_id', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testCopySqlPgsqlInnerWithCoalesceQualifiesOldValue(): void {
    $sql = $this->makeManager('pgsql')->pubGetCopySql('src_t', 'value', 'tgt_t', 'value', 'INNER', TRUE);
    $this->assertStringContainsString('COALESCE(s.value, t.value)', $sql);
  }

  public function testCopySqlSqliteInnerWithCoalesceQualifiesOldValue(): void {
    $sql = $this->makeManager('sqlite')->pubGetCopySql('src_t', 'value', 'tgt_t', 'value', 'INNER', TRUE);
    $this->assertStringContainsString('COALESCE(s.value, t.value)', $sql);
  }

  public function testCopySqlPgsqlLeftUsesCorrelatedSubquery(): void {
    $sql = $this->makeManager('pgsql')->pubGetCopySql('src_t', 'src_c', 'tgt_t', 'tgt_c', 'LEFT', FALSE);
    $this->assertStringContainsString('(SELECT s.src_c FROM {src_t} s WHERE s.item_id = t.item_id)', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testCopySqlSqliteLeftWithCoalesceKeepsOldValue(): void {
    $sql = $this->makeManager('sqlite')->pubGetCopySql('src_t', 'src_c', 'tgt_t', 'tgt_c', 'LEFT', TRUE);
    $this->assertStringContainsString('COALESCE(', $sql);
    $this->assertStringContainsString('SELECT s.src_c FROM {src_t}', $sql);
    $this->assertStringContainsString('tgt_c)', $sql);
  }

  private const UNION_PARTS = ['SELECT item_id, val FROM {a}'];

  public function testFillFromUnionMysqlUsesJoin(): void {
    $sql = $this->makeManager('mysql')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'MIN', 'INNER');
    $this->assertStringContainsString('JOIN (', $sql);
  }

  public function testFillFromUnionPgsqlInnerUsesFromWhere(): void {
    $sql = $this->makeManager('pgsql')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'MIN', 'INNER');
    $this->assertStringContainsString('FROM (', $sql);
    $this->assertStringContainsString('WHERE agg.item_id = t.item_id', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testFillFromUnionPgsqlLeftUsesCorrelatedSubquery(): void {
    $sql = $this->makeManager('pgsql')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'MIN', 'LEFT');
    $this->assertStringContainsString('SET col = (', $sql);
    $this->assertStringContainsString('WHERE union_src.item_id = t.item_id', $sql);
    $this->assertStringNotContainsString('JOIN', $sql);
  }

  public function testFillFromUnionGroupConcatMysqlUsesSeparatorKeyword(): void {
    $sql = $this->makeManager('mysql')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'GROUP_CONCAT', 'INNER');
    $this->assertStringContainsString("GROUP_CONCAT(union_src.val SEPARATOR ',')", $sql);
  }

  public function testFillFromUnionGroupConcatPgsqlUsesStringAgg(): void {
    $sql = $this->makeManager('pgsql')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'GROUP_CONCAT', 'INNER');
    $this->assertStringContainsString("STRING_AGG(CAST(union_src.val AS TEXT), ',')", $sql);
  }

  public function testFillFromUnionGroupConcatSqliteUsesTwoArgForm(): void {
    $sql = $this->makeManager('sqlite')->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'GROUP_CONCAT', 'INNER');
    $this->assertStringContainsString("GROUP_CONCAT(union_src.val, ',')", $sql);
  }

  public function testFillFromUnionMinIsIdenticalCallSyntaxOnEveryDriver(): void {
    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
      $sql = $this->makeManager($driver)->pubGetFillFromUnionSql('tgt_t', 'col', self::UNION_PARTS, 'MIN', 'INNER');
      $this->assertStringContainsString('MIN(union_src.val)', $sql, "driver: {$driver}");
    }
  }

  private const PF_SOURCES = [
    ['table' => 'a', 'column' => 'x'],
    ['table' => 'b', 'column' => 'y'],
  ];

  public function testPriorityFillMysqlUsesLeftJoinChain(): void {
    $sql = $this->makeManager('mysql')->pubGetPriorityFillSql('tgt_t', 'col', self::PF_SOURCES);
    $this->assertStringContainsString('LEFT JOIN {a} pf0', $sql);
    $this->assertStringContainsString('LEFT JOIN {b} pf1', $sql);
    $this->assertStringContainsString('COALESCE(pf0.x, pf1.y)', $sql);
  }

  public function testPriorityFillPgsqlUsesCorrelatedSubqueries(): void {
    $sql = $this->makeManager('pgsql')->pubGetPriorityFillSql('tgt_t', 'col', self::PF_SOURCES);
    $this->assertStringNotContainsString('JOIN', $sql);
    $this->assertStringContainsString('(SELECT x FROM {a} WHERE item_id = t.item_id)', $sql);
    $this->assertStringContainsString('(SELECT y FROM {b} WHERE item_id = t.item_id)', $sql);
    $this->assertStringContainsString('COALESCE(', $sql);
  }

  public function testPriorityFillSqliteUsesCorrelatedSubqueries(): void {
    $sql = $this->makeManager('sqlite')->pubGetPriorityFillSql('tgt_t', 'col', self::PF_SOURCES);
    $this->assertStringNotContainsString('JOIN', $sql);
    $this->assertStringContainsString('(SELECT x FROM {a} WHERE item_id = t.item_id)', $sql);
  }

}
