<?php

namespace Drupal\search_api_sql_aggregator\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class IndexCompleteAggregationSubscriber implements EventSubscriberInterface {

  protected $configFactory;
  protected $manager;
  protected $logger;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AggregationManager $manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->manager = $manager;
    $this->logger = $logger_factory->get('search_api_sql_aggregator');
  }

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::ITEMS_INDEXED => ['onItemsIndexed', 0]];
  }

  public function onItemsIndexed(ItemsIndexedEvent $event): void {
    try {
      $this->maybeTriggerAggregation($event);
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'search_api.items_indexed listener failed — Search API indexing itself is unaffected: @msg',
        ['@msg' => $e->getMessage()]
      );
    }
  }

  protected function maybeTriggerAggregation(ItemsIndexedEvent $event): void {
    $config = $this->configFactory->get('search_api_sql_aggregator.settings');

    if (!$config->get('trigger_on_index_complete')) {
      return;
    }
    if (!$config->get('config_valid')) {
      return;
    }

    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));
    if (empty($blocks) || !\is_array($blocks)) {
      return;
    }

    if (\count($event->getProcessedIds()) <= 1) {
      return;
    }

    $index = $event->getIndex();
    $tracker = $index->getTrackerInstanceIfAvailable();
    if (!$tracker || $tracker->getRemainingItemsCount() > 0) {
      return;
    }

    if (!$this->configReferencesIndex($blocks, $index)) {
      return;
    }

    $this->manager->processAggregation(
      $blocks,
      (int) $config->get('time_limit'),
      (int) $config->get('sql_time_limit'),
      'index',
      (string) ($config->get('custom_sql_signature') ?? ''),
      (string) ($config->get('custom_sql_unsafe_signature') ?? '')
    );
  }

  protected function configReferencesIndex(array $blocks, IndexInterface $index): bool {
    $indexTables = [];
    $indexTables[OperationValidator::SAFE_TABLE_PREFIX . $index->id()] = TRUE;
    foreach ($this->manager->getIndexDbInfo($index)['field_tables'] ?? [] as $fieldInfo) {
      if (!empty($fieldInfo['table'])) {
        $indexTables[$fieldInfo['table']] = TRUE;
      }
    }

    foreach ($blocks as $block) {
      if (!\is_array($block)) {
        continue;
      }
      foreach ($this->extractTableNames($block) as $table) {
        if (\is_string($table) && isset($indexTables[$table])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  protected function extractTableNames(array $block): array {
    $tables = [];
    foreach (['target_table', 'source_table'] as $key) {
      if (isset($block[$key])) {
        $tables[] = $block[$key];
      }
    }
    if (isset($block['source_tables']) && \is_array($block['source_tables'])) {
      foreach ($block['source_tables'] as $t) {
        $tables[] = $t;
      }
    }
    if (isset($block['sources']) && \is_array($block['sources'])) {
      foreach ($block['sources'] as $src) {
        if (\is_array($src) && isset($src['table'])) {
          $tables[] = $src['table'];
        }
      }
    }
    if (isset($block['options']['main_table'])) {
      $tables[] = $block['options']['main_table'];
    }
    return $tables;
  }

}
