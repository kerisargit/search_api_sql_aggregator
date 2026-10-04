<?php

namespace Drupal\Tests\search_api_sql_aggregator\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_sql_aggregator\Form\SettingsForm;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @group search_api_sql_aggregator
 * @group search_api_sql_aggregator_kernel
 */
class IntervalTriggerIntegrationTest extends KernelTestBase {

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
      'json_config' => json_encode([['type' => 'null_reset', 'target_table' => 'search_api_db_x', 'target_column' => 'c']]),
      'config_valid' => TRUE,
      'run_on_cron' => FALSE,
      'trigger_on_request' => FALSE,
      'schedule_type' => 'interval',
      'schedule_interval' => 0,
      'time_limit' => 60,
      'sql_time_limit' => 1000,
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // KernelTestBase uses NullLockBackend, which never reports contention.
    $container->register('lock', DatabaseLockBackend::class)->addArgument(new Reference('database'));
    // No Search API here; the backend preflight has its own test.
    $container->getDefinition('search_api_sql_aggregator.validator')->setClass(AlwaysAvailableOperationValidator::class);
  }

  protected function lastRun(): ?array {
    return $this->container->get('state')->get('search_api_sql_aggregator.last_run');
  }

  public function testTaskIsRegisteredWithTheRunner(): void {
    $runner = $this->container->get('interval_trigger.runner');
    $tasks = (new \ReflectionProperty($runner, 'tasks'))->getValue($runner);
    $this->assertArrayHasKey('search_api_sql_aggregator.aggregation_task', $tasks);
  }

  public function testCronRunsOnlyWhenRunOnCronIsEnabled(): void {
    interval_trigger_cron();
    $this->assertNull($this->lastRun(), 'run_on_cron off: cron must not start a run.');

    $this->config('search_api_sql_aggregator.settings')->set('run_on_cron', TRUE)->save();
    interval_trigger_cron();
    $this->assertSame('cron', $this->lastRun()['trigger'] ?? NULL);
  }

  public function testRequestChannelRunsOnlyWhenTriggerOnRequestIsEnabled(): void {
    $runner = $this->container->get('interval_trigger.runner');
    $runner->runDueTasks('request');
    $this->assertNull($this->lastRun(), 'trigger_on_request off: requests must not start a run.');

    $this->config('search_api_sql_aggregator.settings')->set('trigger_on_request', TRUE)->save();
    $runner->runDueTasks('request');
    $this->assertSame('request', $this->lastRun()['trigger'] ?? NULL);
  }

  public function testScheduleIsRespected(): void {
    $this->config('search_api_sql_aggregator.settings')
      ->set('run_on_cron', TRUE)
      ->set('schedule_interval', 3600)
      ->save();
    $this->container->get('state')->set('search_api_sql_aggregator.last_run', [
      'timestamp' => $this->container->get('datetime.time')->getCurrentTime() - 60,
      'trigger' => 'manual',
    ]);
    interval_trigger_cron();
    $this->assertSame('manual', $this->lastRun()['trigger'], 'Interval not elapsed: no new run.');
  }

  public function testNoOwnTerminateSubscriberOrCronHookAnyMore(): void {
    foreach ($this->container->get('event_dispatcher')->getListeners(KernelEvents::TERMINATE) as $listener) {
      $class = \is_array($listener) && \is_object($listener[0]) ? \get_class($listener[0]) : '';
      $this->assertStringNotContainsString('search_api_sql_aggregator', $class);
    }
    $this->assertFalse(\function_exists('search_api_sql_aggregator_cron'));
  }

  public function testSettingsFormSharesOneScheduleForBothTriggers(): void {
    $this->config('search_api_sql_aggregator.settings')->set('trigger_on_request', TRUE)->save();
    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);

    $schedule = $form['cron_settings']['schedule'];
    $this->assertContains('or', $schedule['#states']['visible']);
    $this->assertArrayHasKey('schedule_type', $schedule);
    $this->assertArrayHasKey('interval_count', $schedule['interval_wrapper']);
    $this->assertArrayHasKey('schedule_time', $schedule['schedule_time_wrapper']);
    $this->assertStringContainsString('on every cron run or visit', (string) $schedule['next_run_info']['#value']);
    $this->assertStringContainsString('/admin/config/system/interval-trigger', (string) $form['cron_settings']['trigger_on_request']['#description']);
  }

  public function testSettingsFormRejectsOutOfRangeTime(): void {
    $form_state = (new FormState())->setValues([
      'schedule_type' => 'daily',
      'schedule_time' => '25:00',
      'interval_count' => 0,
      'interval_unit' => 'minutes',
      'json_config' => '[]',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertArrayHasKey('schedule_time', $form_state->getErrors());
  }

  /**
   * "Run Aggregation Now" reports what actually happened, not always success.
   */
  public function testRunNowReportsTheRealOutcome(): void {
    $form_object = SettingsForm::create($this->container);
    $form = [];
    $messenger = $this->container->get('messenger');

    // No Search API index: the run itself fails (empty table whitelist).
    $form_object->runSavedHandler($form, new FormState());
    $this->assertNotEmpty($messenger->messagesByType('error'));
    $this->assertEmpty($messenger->messagesByType('status'));
    $messenger->deleteAll();

    // Another process holds the lock (a second backend instance has its own
    // lock id; the request's own backend would simply re-acquire).
    (new DatabaseLockBackend($this->container->get('database')))->acquire('search_api_sql_aggregator_run', 60);
    $form_object->runSavedHandler($form, new FormState());
    $this->assertStringContainsString('still in progress', (string) $messenger->messagesByType('warning')[0]);
  }

  public function testRequirements(): void {
    \Drupal::moduleHandler()->loadInclude('search_api_sql_aggregator', 'install');
    $this->assertSame(OperationValidator::SQLITE_MINIMUM_VERSION, SEARCH_API_SQL_AGGREGATOR_SQLITE_MINIMUM_VERSION);

    $runtime = search_api_sql_aggregator_requirements('runtime');
    $this->assertArrayNotHasKey('search_api_sql_aggregator_interval_trigger', $runtime, 'interval_trigger is installed here.');
    $this->assertArrayNotHasKey('search_api_sql_aggregator_sqlite_version', search_api_sql_aggregator_requirements('install'));
  }

  public function testRequirementsFlagMissingIntervalTrigger(): void {
    $this->disableModules(['interval_trigger']);
    \Drupal::moduleHandler()->loadInclude('search_api_sql_aggregator', 'install');
    $runtime = search_api_sql_aggregator_requirements('runtime');
    $this->assertArrayHasKey('search_api_sql_aggregator_interval_trigger', $runtime);
    $this->assertSame(REQUIREMENT_ERROR, $runtime['search_api_sql_aggregator_interval_trigger']['severity']);
  }

  public function testUpdateHookIsIdempotentWhenIntervalTriggerIsInstalled(): void {
    \Drupal::moduleHandler()->loadInclude('search_api_sql_aggregator', 'install');
    $message = (string) search_api_sql_aggregator_update_10001();
    $this->assertStringContainsString('already installed', $message);
  }

}
