<?php

namespace Drupal\Tests\search_api_sql_aggregator\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_sql_aggregator\Form\SettingsForm;

/**
 * Nothing to run on (no Database-backend index): one clear refusal.
 *
 * Instead of a failed run (and an error entry) every cron interval, or an
 * error per operation.
 *
 * @group search_api_sql_aggregator
 * @group search_api_sql_aggregator_kernel
 */
class BackendPreflightTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'interval_trigger',
    'search_api_sql_aggregator',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'interval_trigger']);
    $this->config('search_api_sql_aggregator.settings')->setData([
      'json_config' => json_encode([
        ['type' => 'null_reset', 'target_table' => 'search_api_db_x', 'target_column' => 'c'],
        ['type' => 'null_reset', 'target_table' => 'search_api_db_y', 'target_column' => 'c'],
      ]),
      'config_valid' => TRUE,
      'run_on_cron' => TRUE,
      'schedule_type' => 'interval',
      'schedule_interval' => 0,
    ])->save();
  }

  public function testScheduledRunsDoNotStart(): void {
    interval_trigger_cron();
    $this->assertNull($this->container->get('state')->get('search_api_sql_aggregator.last_run'));
    $this->assertSame([], $this->container->get('state')->get('search_api_sql_aggregator.run_history', []));
  }

  public function testValidationReportsOneReason(): void {
    $results = $this->container->get('search_api_sql_aggregator.manager')->validateOperations(
      json_decode($this->config('search_api_sql_aggregator.settings')->get('json_config'), TRUE)
    );
    $this->assertCount(1, $results);
    $this->assertSame('Global', $results[0]['target']);
    $this->assertStringContainsString('No Search API index uses the Database backend', $results[0]['msg']);
  }

  public function testManualRunReportsOneReason(): void {
    $form = [];
    SettingsForm::create($this->container)->runSavedHandler($form, new FormState());
    $run = $this->container->get('state')->get('search_api_sql_aggregator.last_run');
    $this->assertSame('ERROR', $run['status']);
    $this->assertCount(1, $run['log']);
    $this->assertStringContainsString('No Search API index uses the Database backend', $run['log'][0]['msg']);
  }

  public function testStatusReportExplains(): void {
    \Drupal::moduleHandler()->loadInclude('search_api_sql_aggregator', 'install');
    $requirements = search_api_sql_aggregator_requirements('runtime');
    $this->assertSame(REQUIREMENT_WARNING, $requirements['search_api_sql_aggregator_backend']['severity']);
    $this->assertStringContainsString('No Search API index uses the Database backend', (string) $requirements['search_api_sql_aggregator_backend']['description']);
  }

}
