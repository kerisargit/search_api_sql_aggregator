<?php

namespace Drupal\Tests\search_api_sql_aggregator\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;

class KernelTestableOperationValidator extends OperationValidator {

  private array $allowedTables = [];

  public function setAllowedTables(array $tables): void {
    $this->allowedTables = $tables;
  }

  public function getAllSearchApiTables(): array {
    return $this->allowedTables;
  }

  // The table list above stands in for real Search API indexes.
  public function assertSqlIndexesAvailable(): void {}
}

/**
 * @group search_api_sql_aggregator
 * @group search_api_sql_aggregator_kernel
 */
class AggregationManagerExecutionTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'search_api_sql_aggregator',
  ];

  private AggregationManager $mgr;
  private \Drupal\Core\Database\Connection $db;

  private const T_MAIN    = 'search_api_db_test_main';
  private const T_FIELD_A = 'search_api_db_test_field_a';
  private const T_FIELD_B = 'search_api_db_test_field_b';
  private const T_AGG     = 'search_api_db_test_field_agg';

  protected function setUp(): void {
    parent::setUp();

    $this->setSetting('search_api_sql_aggregator_allow_custom_sql', TRUE);
    $this->setSetting('search_api_sql_aggregator_custom_sql_run_key', AggregationManager::customSqlRunKey());

    $this->db = $this->container->get('database');

    $logger  = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $validator = new KernelTestableOperationValidator(
      $this->db,
      $factory,
      $entityTypeManager,
      $this->container->get('keyvalue')
    );
    $validator->setAllowedTables([
      self::T_MAIN, self::T_FIELD_A, self::T_FIELD_B, self::T_AGG,
    ]);

    $this->mgr = new AggregationManager(
      $this->db,
      $factory,
      $this->container->get('state'),
      $this->container->get('lock'),
      $entityTypeManager,
      $validator
    );

    $this->createTestSchema();
  }

  protected function tearDown(): void {
    $this->dropTestSchema();
    parent::tearDown();
  }

  private function createTestSchema(): void {
    $s = $this->db->schema();

    $s->createTable(self::T_MAIN, [
      'fields' => [
        'item_id'   => ['type' => 'varchar', 'length' => 150, 'not null' => TRUE, 'default' => ''],
        'field_agg' => ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
        'field_b'   => ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
      ],
      'primary key' => ['item_id'],
    ]);

    foreach ([self::T_FIELD_A, self::T_FIELD_B, self::T_AGG] as $t) {
      $s->createTable($t, [
        'fields' => [
          'item_id' => ['type' => 'varchar', 'length' => 150, 'not null' => TRUE, 'default' => ''],
          'value'   => ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
        ],
        'indexes' => ['item_id' => ['item_id']],
      ]);
    }
  }

  private function dropTestSchema(): void {
    $s = $this->db->schema();
    foreach ([self::T_MAIN, self::T_FIELD_A, self::T_FIELD_B, self::T_AGG] as $t) {
      if ($s->tableExists($t)) {
        $s->dropTable($t);
      }
    }
  }

  private function insertRows(string $table, array $rows): void {
    foreach ($rows as [$id, $val]) {
      $this->db->insert($table)
        ->fields(['item_id' => $id, 'value' => $val])
        ->execute();
    }
  }

  private function insertMain(string $id, ?int $agg = NULL, ?int $b = NULL): void {
    $this->db->insert(self::T_MAIN)
      ->fields(['item_id' => $id, 'field_agg' => $agg, 'field_b' => $b])
      ->execute();
  }

  private function runAggregation(array $blocks, string $customSqlSignature = '', string $customSqlUnsafeSignature = ''): array {
    $this->mgr->processAggregation($blocks, 240, 30000, 'manual', $customSqlSignature, $customSqlUnsafeSignature);
    return $this->container->get('state')
      ->get('search_api_sql_aggregator.last_run', []);
  }

  private function fetchField(string $col): array {
    return $this->db->select(self::T_MAIN, 'm')
      ->fields('m', ['item_id', $col])
      ->execute()
      ->fetchAllKeyed();
  }

  private function fetchAll(string $table): array {
    return $this->db->select($table, 't')
      ->fields('t')
      ->execute()
      ->fetchAllAssoc('item_id', \PDO::FETCH_ASSOC);
  }

  public function testNullResetSetsColumnToNull(): void {
    $this->insertMain('entity:node/1', 42);
    $this->insertMain('entity:node/2', 99);

    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
    ]]);

    $this->assertSame('SUCCESS', $state['status']);
    $this->assertNull($this->fetchField('field_agg')['entity:node/1']);
    $this->assertNull($this->fetchField('field_agg')['entity:node/2']);
  }

  public function testNullResetOnAlreadyNullIsNoOp(): void {
    $this->insertMain('entity:node/1', NULL);
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
    ]]);
    $this->assertSame('SUCCESS', $state['status']);
    $this->assertNull($this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCopyPropagatesValue(): void {
    $this->insertMain('entity:node/1', 7, NULL);

    $this->runAggregation([[
      'type'          => 'copy',
      'source_table'  => self::T_MAIN,
      'source_column' => 'field_agg',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_b',
    ]]);

    $this->assertSame('7', $this->fetchField('field_b')['entity:node/1']);
  }

  public function testCopyLeftJoinNullsUnmatchedRows(): void {
    $this->insertMain('entity:node/1', NULL, NULL);
    $this->insertMain('entity:node/2', NULL, NULL);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 55]]);

    $this->runAggregation([[
      'type'          => 'copy',
      'source_table'  => self::T_FIELD_A,
      'source_column' => 'value',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_b',
      'join_type'     => 'LEFT',
    ]]);

    $rows = $this->fetchField('field_b');
    $this->assertSame('55', $rows['entity:node/1']);
    $this->assertNull($rows['entity:node/2']);
  }

  public function testAggregateDeduplicatesAcrossSources(): void {
    $this->insertRows(self::T_FIELD_A, [
      ['entity:node/1', 10],
      ['entity:node/2', 20],
    ]);
    $this->insertRows(self::T_FIELD_B, [
      ['entity:node/1', 10],
      ['entity:node/3', 30],
    ]);

    $state = $this->runAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A, self::T_FIELD_B],
      'options'       => ['hierarchy' => FALSE],
    ]]);

    $this->assertSame('SUCCESS', $state['status']);
    $rows = $this->fetchAll(self::T_AGG);
    $this->assertCount(3, $rows);
    $this->assertSame('10', $rows['entity:node/1']['value']);
  }

  public function testAggregateClearsStaleDataBeforeInsert(): void {
    $this->insertRows(self::T_AGG, [['entity:node/stale', 999]]);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 1]]);

    $this->runAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A],
      'options'       => ['hierarchy' => FALSE],
    ]]);

    $rows = $this->fetchAll(self::T_AGG);
    $this->assertArrayNotHasKey('entity:node/stale', $rows);
    $this->assertArrayHasKey('entity:node/1', $rows);
  }

  public function testAggregateRollsBackClearStepWhenLaterSourceFails(): void {
    $s = $this->db->schema();
    $strictTarget = 'search_api_db_test_strict_agg';
    $s->createTable($strictTarget, [
      'fields' => [
        'item_id' => ['type' => 'varchar', 'length' => 150, 'not null' => TRUE, 'default' => ''],
        'value'   => ['type' => 'int', 'size' => 'normal', 'not null' => TRUE],
      ],
      'indexes' => ['item_id' => ['item_id']],
    ]);

    try {
      $this->insertRows($strictTarget, [['entity:node/existing', 1]]);

      $this->insertRows(self::T_FIELD_A, [['entity:node/1', 42]]);
      $this->db->insert(self::T_FIELD_B)
        ->fields(['item_id' => 'entity:node/2', 'value' => NULL])
        ->execute();

      $state = $this->runAggregation([[
        'type'          => 'aggregate',
        'target_table'  => $strictTarget,
        'source_tables' => [self::T_FIELD_A, self::T_FIELD_B],
        'options'       => ['hierarchy' => FALSE],
      ]]);

      $this->assertSame('WARNING', $state['status']);
      $this->assertSame('ERROR', $state['log'][0]['status']);

      $rows = $this->db->select($strictTarget, 't')->fields('t')
        ->execute()->fetchAllAssoc('item_id', \PDO::FETCH_ASSOC);
      $this->assertCount(1, $rows, 'Target must be restored to its pre-run state, not left cleared/partial.');
      $this->assertArrayHasKey('entity:node/existing', $rows);
      $this->assertArrayNotHasKey('entity:node/1', $rows);
    }
    finally {
      $s->dropTable($strictTarget);
    }
  }

  public function testAggregateUpdatesMainColumn(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 77]]);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 33]]);

    $state = $this->runAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A, self::T_FIELD_B],
      'options'       => [
        'hierarchy'   => FALSE,
        'main_table'  => self::T_MAIN,
        'main_column' => 'field_agg',
      ],
    ]]);

    $this->assertSame('SUCCESS', $state['status']);
    $this->assertSame('33', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testAggregateMultipleItemsMainColumn(): void {
    foreach (['entity:node/1', 'entity:node/2', 'entity:node/3'] as $id) {
      $this->insertMain($id, NULL);
    }
    $this->insertRows(self::T_FIELD_A, [
      ['entity:node/1', 10],
      ['entity:node/2', 20],
    ]);
    $this->insertRows(self::T_FIELD_B, [
      ['entity:node/2', 5],
      ['entity:node/3', 30],
    ]);

    $this->runAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A, self::T_FIELD_B],
      'options'       => [
        'hierarchy'   => FALSE,
        'main_table'  => self::T_MAIN,
        'main_column' => 'field_agg',
      ],
    ]]);

    $rows = $this->fetchField('field_agg');
    $this->assertSame('10', $rows['entity:node/1']);
    $this->assertSame('5',  $rows['entity:node/2']);
    $this->assertSame('30', $rows['entity:node/3']);
  }

  public function testAggregateIsIdempotent(): void {
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 10]]);

    $this->mgr->processAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A],
      'options'       => ['hierarchy' => FALSE],
    ]]);
    $firstCount = count($this->fetchAll(self::T_AGG));

    $this->mgr->processAggregation([[
      'type'          => 'aggregate',
      'target_table'  => self::T_AGG,
      'source_tables' => [self::T_FIELD_A],
      'options'       => ['hierarchy' => FALSE],
    ]]);
    $secondCount = count($this->fetchAll(self::T_AGG));

    $this->assertSame($firstCount, $secondCount, 'Re-running aggregate must produce identical result.');
  }

  public function testFillFromUnionMin(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 100]]);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 50]]);

    $this->runAggregation([[
      'type'           => 'fill_from_union',
      'target_table'   => self::T_MAIN,
      'target_column'  => 'field_agg',
      'sources'        => [
        ['table' => self::T_FIELD_A, 'column' => 'value'],
        ['table' => self::T_FIELD_B, 'column' => 'value'],
      ],
      'aggregate_func' => 'MIN',
    ]]);

    $this->assertSame('50', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testFillFromUnionMax(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 100]]);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 50]]);

    $this->runAggregation([[
      'type'           => 'fill_from_union',
      'target_table'   => self::T_MAIN,
      'target_column'  => 'field_agg',
      'sources'        => [
        ['table' => self::T_FIELD_A, 'column' => 'value'],
        ['table' => self::T_FIELD_B, 'column' => 'value'],
      ],
      'aggregate_func' => 'MAX',
    ]]);

    $this->assertSame('100', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testFillFromUnionCount(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_A, [
      ['entity:node/1', 1],
      ['entity:node/1', 2],
    ]);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 3]]);

    $this->runAggregation([[
      'type'           => 'fill_from_union',
      'target_table'   => self::T_MAIN,
      'target_column'  => 'field_agg',
      'sources'        => [
        ['table' => self::T_FIELD_A, 'column' => 'value'],
        ['table' => self::T_FIELD_B, 'column' => 'value'],
      ],
      'aggregate_func' => 'COUNT',
    ]]);

    $this->assertSame('3', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testFillFromUnionLeftJoinNullsUnmatchedRows(): void {
    $this->insertMain('entity:node/1', 42);
    $this->insertMain('entity:node/2', 42);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 99]]);

    $this->runAggregation([[
      'type'           => 'fill_from_union',
      'target_table'   => self::T_MAIN,
      'target_column'  => 'field_agg',
      'sources'        => [['table' => self::T_FIELD_A, 'column' => 'value']],
      'aggregate_func' => 'MIN',
      'join_type'      => 'LEFT',
    ]]);

    $rows = $this->fetchField('field_agg');
    $this->assertSame('99', $rows['entity:node/1']);
    $this->assertNull($rows['entity:node/2']);
  }

  public function testPriorityFillPicksFirstNonNull(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 55]]);

    $this->runAggregation([[
      'type'          => 'priority_fill',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
      'sources'       => [
        ['table' => self::T_FIELD_A, 'column' => 'value'],
        ['table' => self::T_FIELD_B, 'column' => 'value'],
      ],
    ]]);

    $this->assertSame('55', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testPriorityFillHigherPriorityWins(): void {
    $this->insertMain('entity:node/1', NULL);
    $this->insertRows(self::T_FIELD_A, [['entity:node/1', 10]]);
    $this->insertRows(self::T_FIELD_B, [['entity:node/1', 99]]);

    $this->runAggregation([[
      'type'          => 'priority_fill',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
      'sources'       => [
        ['table' => self::T_FIELD_A, 'column' => 'value'],
        ['table' => self::T_FIELD_B, 'column' => 'value'],
      ],
    ]]);

    $this->assertSame('10', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlExecutesUpdate(): void {
    $this->insertMain('entity:node/1', 1);
    $this->insertMain('entity:node/2', 2);

    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]];
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $this->assertSame('SUCCESS', $state['status']);
    $this->assertSame('0', $this->fetchField('field_agg')['entity:node/1']);
    $this->assertSame('0', $this->fetchField('field_agg')['entity:node/2']);
  }

  public function testCustomSqlReturnsRowCountInLog(): void {
    $this->insertMain('entity:node/1', 5);

    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 99',
    ]];
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $log = $state['log'][0] ?? [];
    $this->assertStringContainsString('1 row', $log['msg']);
  }

  public function testCustomSqlWithoutSignatureIsRejected(): void {
    $this->insertMain('entity:node/1', 5);

    $state = $this->runAggregation([[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]]);

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('signature', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlWithMismatchedSignatureIsRejected(): void {
    $this->insertMain('entity:node/1', 5);
    $bogusSignature = $this->mgr->computeCustomSqlSignature([[
      'type' => 'custom_sql',
      'sql'  => 'SELECT 1',
    ]]);

    $state = $this->runAggregation([[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]], $bogusSignature);

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('signature', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlWriteToRealNonSearchApiTableIsRejectedAtExecution(): void {
    $this->insertMain('entity:node/1', 5);
    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {key_value} SET value = 999',
    ]];
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('does not start with', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlWriteSourcedFromRealNonSearchApiTableSucceeds(): void {
    $this->db->schema()->createTable('sasa_test_unrelated', [
      'fields' => [
        'id'    => ['type' => 'varchar', 'length' => 150, 'not null' => TRUE, 'default' => ''],
        'value' => ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
      ],
      'primary key' => ['id'],
    ]);
    try {
      $this->db->insert('sasa_test_unrelated')->fields(['id' => 'x', 'value' => 777])->execute();
      $this->insertMain('entity:node/1', 5);

      $blocks = [[
        'type' => 'custom_sql',
        'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = ' .
          "(SELECT value FROM sasa_test_unrelated WHERE id = 'x')",
      ]];
      $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

      $this->assertSame('SUCCESS', $state['status']);
      $this->assertSame('777', $this->fetchField('field_agg')['entity:node/1']);
    }
    finally {
      $this->db->schema()->dropTable('sasa_test_unrelated');
    }
  }

  public function testCustomSqlRefusedWithoutRunKeyEvenWithValidSignature(): void {
    $this->setSetting('search_api_sql_aggregator_custom_sql_run_key', '');

    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]];
    $this->insertMain('entity:node/1', 5);
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('not activated for execution', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlRefusedWhenRunKeyAndSignatureBothAbsent(): void {
    $this->setSetting('search_api_sql_aggregator_custom_sql_run_key', '');

    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]];
    $this->insertMain('entity:node/1', 5);
    $state = $this->runAggregation($blocks, '');

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlWriteTargetWhitelistStillAppliesWithValidRunKey(): void {
    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {key_value} SET value = 999',
    ]];
    $this->insertMain('entity:node/1', 5);
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('does not start with', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testCustomSqlCreateKeyAndRunKeyAreDifferent(): void {
    $this->assertNotSame(
      AggregationManager::customSqlCreateKey(),
      AggregationManager::customSqlRunKey()
    );
  }

  public function testCustomSqlUnsafeRefusedWhenSubmoduleNotInstalled(): void {
    $blocks = [[
      'type'      => 'custom_sql_unsafe',
      'sql'       => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 1',
      'confirmed' => TRUE,
    ]];
    $this->insertMain('entity:node/1', 5);
    $state = $this->runAggregation($blocks, '', 'irrelevant-signature-value');

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase("'Unsafe Custom SQL' submodule", $state['log'][0]['msg']);
    $this->assertStringContainsStringIgnoringCase('is not installed', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

  public function testValidateOperationsRefusesCustomSqlUnsafeWhenSubmoduleNotInstalled(): void {
    $validator = new KernelTestableOperationValidator(
      $this->db,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->container->get('keyvalue')
    );
    $validator->setAllowedTables([self::T_MAIN]);

    $results = $validator->validateOperations([[
      'type'      => 'custom_sql_unsafe',
      'sql'       => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 1',
      'confirmed' => TRUE,
    ]]);

    $this->assertSame('error', $results[0]['status']);
    $this->assertStringContainsStringIgnoringCase('is not installed', $results[0]['msg']);
  }

  public function testComputeCustomSqlSignatureIsStableForSameContent(): void {
    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]];
    $this->assertSame(
      $this->mgr->computeCustomSqlSignature($blocks),
      $this->mgr->computeCustomSqlSignature($blocks)
    );
  }

  public function testComputeCustomSqlSignatureIsEmptyWithoutCustomSqlBlocks(): void {
    $this->assertSame('', $this->mgr->computeCustomSqlSignature([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
    ]]));
  }

  public function testSuccessfulRunSavesStateWithTimestamp(): void {
    $before = time();
    $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => 'field_agg',
    ]]);
    $state = $this->container->get('state')
      ->get('search_api_sql_aggregator.last_run');

    $this->assertGreaterThanOrEqual($before, $state['timestamp']);
    $this->assertSame('SUCCESS', $state['status']);
    $this->assertIsFloat($state['total_time']);
    $this->assertIsArray($state['log']);
  }

  public function testFailedOpResultsInWarningStatus(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => 'search_api_db_nonexistent_xyz',
      'target_column' => 'value',
    ]]);

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
  }

  public function testMultipleOpsAllLoggedIndividually(): void {
    $this->insertMain('entity:node/1', 10);

    $state = $this->runAggregation([
      [
        'type'          => 'null_reset',
        'target_table'  => self::T_MAIN,
        'target_column' => 'field_agg',
      ],
      [
        'type'          => 'null_reset',
        'target_table'  => self::T_MAIN,
        'target_column' => 'field_b',
      ],
    ]);

    $this->assertCount(2, $state['log']);
    $this->assertSame('SUCCESS', $state['status']);
  }

  public function testRealDbRejectsUnprefixedTableWrite(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => 'users',
      'target_column' => 'name',
    ]]);
    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('does not start with', $state['log'][0]['msg']);
  }

  public function testRealDbRejectsNonExistentTable(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => 'search_api_db_does_not_exist_xyz',
      'target_column' => 'value',
    ]]);
    $this->assertSame('WARNING', $state['status']);
    $this->assertStringContainsStringIgnoringCase('does not exist', $state['log'][0]['msg']);
  }

  public function testRealDbRejectsForbiddenColumnWrite(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => 'item_id',
    ]]);
    $this->assertSame('WARNING', $state['status']);
    $this->assertStringContainsStringIgnoringCase('protected', $state['log'][0]['msg']);
  }

  public function testRealDbRejectsSyntaxInjectionInTableName(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => "search_api_db_test_main; DROP TABLE " . self::T_MAIN,
      'target_column' => 'field_agg',
    ]]);
    $this->assertSame('WARNING', $state['status']);
    $this->assertStringContainsStringIgnoringCase('invalid characters', $state['log'][0]['msg']);

    $this->assertTrue($this->db->schema()->tableExists(self::T_MAIN));
  }

  public function testRealDbRejectsSyntaxInjectionInColumnName(): void {
    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => self::T_MAIN,
      'target_column' => "field_agg; DROP TABLE " . self::T_MAIN,
    ]]);
    $this->assertSame('WARNING', $state['status']);
    $this->assertStringContainsStringIgnoringCase('invalid characters', $state['log'][0]['msg']);
    $this->assertTrue($this->db->schema()->tableExists(self::T_MAIN));
  }

  public function testRealDbRejectionIsIsolated_SubsequentOpsStillRun(): void {
    $this->insertMain('entity:node/1', 99);

    $state = $this->runAggregation([
      [
        'type'          => 'null_reset',
        'target_table'  => 'search_api_db_nonexistent_xyz',
        'target_column' => 'value',
      ],
      [
        'type'          => 'null_reset',
        'target_table'  => self::T_MAIN,
        'target_column' => 'field_agg',
      ],
    ]);

    $this->assertCount(2, $state['log']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertSame('OK',    $state['log'][1]['status']);
    $this->assertNull($this->fetchField('field_agg')['entity:node/1']);
    $this->assertSame('WARNING', $state['status']);
  }

  public function testRealDbRejectsUnregisteredPrefixedTable(): void {
    $unreg = 'search_api_db_test_unregistered';
    $this->db->schema()->createTable($unreg, [
      'fields' => [
        'item_id' => ['type' => 'varchar', 'length' => 150, 'not null' => TRUE, 'default' => ''],
        'value'   => ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
      ],
      'indexes' => ['item_id' => ['item_id']],
    ]);
    $this->db->insert($unreg)->fields(['item_id' => 'entity:node/1', 'value' => 7])->execute();

    $state = $this->runAggregation([[
      'type'          => 'null_reset',
      'target_table'  => $unreg,
      'target_column' => 'value',
    ]]);

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('not registered', $state['log'][0]['msg']);

    $this->db->schema()->dropTable($unreg);
  }

  public function testNonArrayBlocksAreReportedNotFatal(): void {
    $this->insertMain('entity:node/1', 42);

    $state = $this->runAggregation([
      1,
      'garbage',
      [
        'type'          => 'null_reset',
        'target_table'  => self::T_MAIN,
        'target_column' => 'field_agg',
      ],
    ]);

    $this->assertCount(3, $state['log']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertSame('ERROR', $state['log'][1]['status']);
    $this->assertSame('OK',    $state['log'][2]['status']);
    $this->assertNull($this->fetchField('field_agg')['entity:node/1']);
    $this->assertSame('WARNING', $state['status']);
  }

  public function testCustomSqlDisabledBySettingsIsRejected(): void {
    $this->setSetting('search_api_sql_aggregator_allow_custom_sql', FALSE);
    $this->insertMain('entity:node/1', 5);

    $blocks = [[
      'type' => 'custom_sql',
      'sql'  => 'UPDATE {' . self::T_MAIN . '} SET field_agg = 0',
    ]];
    $state = $this->runAggregation($blocks, $this->mgr->computeCustomSqlSignature($blocks));

    $this->assertSame('WARNING', $state['status']);
    $this->assertSame('ERROR', $state['log'][0]['status']);
    $this->assertStringContainsStringIgnoringCase('disabled by site settings', $state['log'][0]['msg']);
    $this->assertSame('5', $this->fetchField('field_agg')['entity:node/1']);
  }

}
