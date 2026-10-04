<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Tracker\TrackerInterface;
use Drupal\search_api_sql_aggregator\EventSubscriber\IndexCompleteAggregationSubscriber;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 */
class IndexCompleteAggregationSubscriberTest extends UnitTestCase {

  private function makeConfig(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $values[$key] ?? NULL);
    return $config;
  }

  private function makeFactory(ImmutableConfig $config): ConfigFactoryInterface {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('search_api_sql_aggregator.settings')->willReturn($config);
    return $factory;
  }

  private function makeSubscriber(
    ConfigFactoryInterface $factory,
    AggregationManager $manager,
    ?LoggerChannelInterface $loggerChannel = NULL
  ): IndexCompleteAggregationSubscriber {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel ?? $this->createMock(LoggerChannelInterface::class));
    return new IndexCompleteAggregationSubscriber($factory, $manager, $loggerFactory);
  }

  private function makeIndex(string $id, array $fieldIds, int $remaining): IndexInterface {
    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willReturn($remaining);

    $fields = [];
    foreach ($fieldIds as $fieldId) {
      $fields[$fieldId] = $this->createMock(\Drupal\search_api\Item\FieldInterface::class);
    }

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn($id);
    $index->method('getFields')->willReturn($fields);
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);
    return $index;
  }

  private const VALID_BLOCKS = '[{"type":"null_reset","target_table":"search_api_db_idx_field_a","target_column":"value"}]';

  public function testSkipsWhenTriggerDisabled(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => FALSE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1', '2']
    ));
    $this->addToAssertionCount(1);
  }

  public function testSkipsWhenConfigInvalid(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => FALSE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1', '2']
    ));
    $this->addToAssertionCount(1);
  }

  public function testSkipsWhenNoBlocksConfigured(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => '[]',
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1', '2']
    ));
    $this->addToAssertionCount(1);
  }

  public function testSkipsSingleItemBatch(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1']
    ));
    $this->addToAssertionCount(1);
  }

  public function testSkipsWhenItemsStillRemaining(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 50), ['1', '2', '3']
    ));
    $this->addToAssertionCount(1);
  }

  public function testSkipsWhenNoTrackerAvailable(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('idx');
    $index->method('getTrackerInstanceIfAvailable')->willReturn(NULL);

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent($index, ['1', '2']));
    $this->addToAssertionCount(1);
  }

  public function testSkipsWhenIndexNotReferencedByConfig(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');
    $manager->method('getIndexDbInfo')->willReturn([
      'field_tables' => ['field_a' => ['table' => 'search_api_db_unrelated_index_field_a', 'column' => 'value']],
    ]);

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('unrelated_index', ['field_a'], 0), ['1', '2']
    ));
    $this->addToAssertionCount(1);
  }

  public function testDoesNotConfusePrefixSimilarIndexIds(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => '[{"type":"null_reset","target_table":"search_api_db_idx_extra_field_a","target_column":"value"}]',
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->expects($this->never())->method('processAggregation');
    $manager->method('getIndexDbInfo')->willReturn([
      'field_tables' => ['field_a' => ['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
    ]);

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1', '2']
    ));
    $this->addToAssertionCount(1);
  }

  public function testRunsWhenAllConditionsMet(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
      'time_limit'                => 240,
      'sql_time_limit'            => 30000,
      'custom_sql_signature'      => '',
    ]);
    $manager = $this->createMock(AggregationManager::class);
    $manager->method('getIndexDbInfo')->willReturn([
      'field_tables' => ['field_a' => ['table' => 'search_api_db_idx_field_a', 'column' => 'value']],
    ]);
    $manager->expects($this->once())
      ->method('processAggregation')
      ->with(
        $this->isType('array'),
        240,
        30000,
        'index',
        ''
      );

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager);
    $subscriber->onItemsIndexed(new ItemsIndexedEvent(
      $this->makeIndex('idx', ['field_a'], 0), ['1', '2', '3']
    ));
  }

  public function testExceptionInDecisionLogicIsCaughtAndLoggedNotRethrown(): void {
    $config = $this->makeConfig([
      'trigger_on_index_complete' => TRUE,
      'config_valid'              => TRUE,
      'json_config'               => self::VALID_BLOCKS,
    ]);
    $manager = $this->createMock(AggregationManager::class);

    $tracker = $this->createMock(TrackerInterface::class);
    $tracker->method('getRemainingItemsCount')->willThrowException(new \Exception('tracker backend unavailable'));
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('idx');
    $index->method('getTrackerInstanceIfAvailable')->willReturn($tracker);

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerChannel->expects($this->once())
      ->method('error')
      ->with($this->anything(), $this->callback(
        fn(array $context) => isset($context['@msg']) && \str_contains($context['@msg'], 'tracker backend unavailable')
      ));

    $subscriber = $this->makeSubscriber($this->makeFactory($config), $manager, $loggerChannel);

    $subscriber->onItemsIndexed(new ItemsIndexedEvent($index, ['1', '2']));
    $this->addToAssertionCount(1);
  }

}
