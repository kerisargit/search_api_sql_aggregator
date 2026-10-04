<?php

namespace Drupal\search_api_sql_aggregator\IntervalTask;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\interval_trigger\IntervalTaskInterface;
use Drupal\search_api_sql_aggregator\Schedule\AggregationSchedule;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;

class AggregationTask implements IntervalTaskInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected AggregationManager $manager,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTaskId(): string {
    return 'search_api_sql_aggregator.aggregation';
  }

  /**
   * {@inheritdoc}
   */
  public function respondsToTrigger(string $trigger): bool {
    $config = $this->configFactory->get('search_api_sql_aggregator.settings');
    return match ($trigger) {
      'cron' => (bool) $config->get('run_on_cron'),
      'request' => (bool) $config->get('trigger_on_request'),
      default => FALSE,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function isDue(): bool {
    $config = $this->configFactory->get('search_api_sql_aggregator.settings');
    if (!$config->get('config_valid') || !$this->blocks()) {
      return FALSE;
    }
    // No point starting a run that can only fail; the status report says why.
    if ($this->manager->unavailableReason() !== NULL) {
      return FALSE;
    }
    $lastRun = (int) ($this->state->get('search_api_sql_aggregator.last_run')['timestamp'] ?? 0);
    return AggregationSchedule::fromConfig($this->configFactory)
      ->isDue($lastRun, $this->time->getCurrentTime());
  }

  /**
   * {@inheritdoc}
   */
  public function run(string $trigger): void {
    $config = $this->configFactory->get('search_api_sql_aggregator.settings');
    $this->manager->processAggregation(
      $this->blocks(),
      (int) $config->get('time_limit'),
      (int) $config->get('sql_time_limit'),
      $trigger,
      (string) ($config->get('custom_sql_signature') ?? ''),
      (string) ($config->get('custom_sql_unsafe_signature') ?? '')
    );
  }

  protected function blocks(): array {
    return AggregationManager::decodeBlocks($this->configFactory->get('search_api_sql_aggregator.settings')->get('json_config'));
  }

}
