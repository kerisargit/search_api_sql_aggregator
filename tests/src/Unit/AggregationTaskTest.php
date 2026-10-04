<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api_sql_aggregator\IntervalTask\AggregationTask;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 * @coversDefaultClass \Drupal\search_api_sql_aggregator\IntervalTask\AggregationTask
 */
class AggregationTaskTest extends UnitTestCase {

  private const BLOCKS = [['type' => 'null_reset', 'target_table' => 'search_api_db_x', 'target_column' => 'c']];

  private function makeTask(array $settings, ?array $lastRun = NULL, ?AggregationManager $manager = NULL, int $now = 1_800_000_000): AggregationTask {
    $settings += [
      'run_on_cron' => FALSE,
      'trigger_on_request' => FALSE,
      'config_valid' => TRUE,
      'json_config' => json_encode(self::BLOCKS),
      'schedule_type' => 'interval',
      'schedule_interval' => 3600,
      'time_limit' => 120,
      'sql_time_limit' => 5000,
      'custom_sql_signature' => 'sig',
      'custom_sql_unsafe_signature' => 'usig',
    ];
    $configFactory = $this->getConfigFactoryStub([
      'search_api_sql_aggregator.settings' => $settings,
      'system.date' => ['timezone' => ['default' => 'UTC']],
    ]);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnMap([['search_api_sql_aggregator.last_run', NULL, $lastRun]]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);

    return new AggregationTask($configFactory, $state, $manager ?? $this->createMock(AggregationManager::class), $time);
  }

  public function testRespondsToTriggerFollowsTheTwoCheckboxes(): void {
    $cronOnly = $this->makeTask(['run_on_cron' => TRUE]);
    $this->assertTrue($cronOnly->respondsToTrigger('cron'));
    $this->assertFalse($cronOnly->respondsToTrigger('request'));

    $requestOnly = $this->makeTask(['trigger_on_request' => TRUE]);
    $this->assertFalse($requestOnly->respondsToTrigger('cron'));
    $this->assertTrue($requestOnly->respondsToTrigger('request'));

    $this->assertFalse($cronOnly->respondsToTrigger('something_else'));
  }

  public function testDueWhenNeverRun(): void {
    $this->assertTrue($this->makeTask([])->isDue());
  }

  public function testNotDueWithinInterval(): void {
    $now = 1_800_000_000;
    $this->assertFalse($this->makeTask([], ['timestamp' => $now - 60], NULL, $now)->isDue());
    $this->assertTrue($this->makeTask([], ['timestamp' => $now - 3600], NULL, $now)->isDue());
  }

  public function testDraftConfigIsNeverDue(): void {
    $this->assertFalse($this->makeTask(['config_valid' => FALSE])->isDue());
  }

  /**
   * @dataProvider provideEmptyConfigs
   */
  public function testEmptyOrBrokenOperationsAreNeverDue(?string $json): void {
    $this->assertFalse($this->makeTask(['json_config' => $json])->isDue());
  }

  public static function provideEmptyConfigs(): array {
    return [
      'null' => [NULL],
      'empty list' => ['[]'],
      'not json' => ['{oops'],
      'scalar' => ['42'],
    ];
  }

  /**
   * Corrupted config/State (bad import, manual edits) must not throw.
   */
  public function testCorruptedDataIsHandled(): void {
    $this->assertFalse($this->makeTask(['json_config' => ['not' => 'a string']])->isDue());
    $this->assertFalse($this->makeTask(['json_config' => 42])->isDue());
    $this->assertTrue($this->makeTask([], ['timestamp' => 'garbage'])->isDue());
    $this->assertTrue($this->makeTask(['schedule_type' => ['x'], 'schedule_interval' => ['y']])->isDue());
  }

  public function testDecodeBlocks(): void {
    $this->assertSame([], AggregationManager::decodeBlocks(NULL));
    $this->assertSame([], AggregationManager::decodeBlocks(['type' => 'copy']));
    $this->assertSame([], AggregationManager::decodeBlocks('{broken'));
    $this->assertSame([], AggregationManager::decodeBlocks('"a string"'));
    $this->assertSame([['type' => 'copy']], AggregationManager::decodeBlocks('[{"type":"copy"}]'));
  }

  public function testRunPassesConfigAndTriggerToManager(): void {
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->once())
      ->method('processAggregation')
      ->with(self::BLOCKS, 120, 5000, 'request', 'sig', 'usig');
    $this->makeTask([], NULL, $manager)->run('request');
  }

}
