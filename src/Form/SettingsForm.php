<?php

namespace Drupal\search_api_sql_aggregator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\search_api_sql_aggregator\Schedule\AggregationSchedule;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;

class SettingsForm extends ConfigFormBase {

  protected $aggregationManager;
  protected $state;
  protected $dateFormatter;
  protected $time;
  protected $currentUser;
  protected $logger;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->aggregationManager = $container->get('search_api_sql_aggregator.manager');
    $instance->state              = $container->get('state');
    $instance->dateFormatter      = $container->get('date.formatter');
    $instance->time               = $container->get('datetime.time');
    $instance->currentUser        = $container->get('current_user');
    $instance->logger             = $container->get('logger.factory')->get('search_api_sql_aggregator');
    return $instance;
  }

  protected function auditCustomSqlSave(array $blocks, string $via): void {
    $custom_sql = $this->customSqlBlockIndexes($blocks);
    if (empty($custom_sql)) {
      return;
    }
    $this->logger->warning('AUDIT: user @uid (@name) saved a configuration with @n Custom SQL block(s) via @via.', [
      '@uid'  => $this->currentUser->id(),
      '@name' => $this->currentUser->getAccountName() ?: 'anonymous',
      '@n'    => \count($custom_sql),
      '@via'  => $via,
    ]);
  }

  protected function auditUnsafeCustomSqlSave(array $blocks, string $via): void {
    $unsafe = $this->unsafeCustomSqlBlockIndexes($blocks);
    if (empty($unsafe)) {
      return;
    }
    $this->logger->warning('AUDIT: user @uid (@name) saved a configuration with @n UNSAFE Custom SQL block(s) via @via.', [
      '@uid'  => $this->currentUser->id(),
      '@name' => $this->currentUser->getAccountName() ?: 'anonymous',
      '@n'    => \count($unsafe),
      '@via'  => $via,
    ]);
  }

  protected function userCanCustomSql(): bool {
    return $this->currentUser->hasPermission('use sql aggregator custom sql');
  }

  protected function userCanUnsafeCustomSql(): bool {
    return $this->currentUser->hasPermission('use sql aggregator unsafe custom sql');
  }

  protected function customSqlBlockIndexes(array $blocks): array {
    $found = [];
    foreach ($blocks as $i => $block) {
      if (\is_array($block) && ($block['type'] ?? NULL) === 'custom_sql') {
        $found[] = $i + 1;
      }
    }
    return $found;
  }

  protected function unsafeCustomSqlBlockIndexes(array $blocks): array {
    $found = [];
    foreach ($blocks as $i => $block) {
      if (\is_array($block) && ($block['type'] ?? NULL) === 'custom_sql_unsafe') {
        $found[] = $i + 1;
      }
    }
    return $found;
  }

  protected function getEditableConfigNames() {
    return ['search_api_sql_aggregator.settings'];
  }

  public function getFormId() {
    return 'search_api_sql_aggregator_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('search_api_sql_aggregator.settings');
    $saved_json_str = $config->get('json_config') ?: '[]';

    $this->addLastRunReport($form);
    $this->addRunHistoryReport($form);
    $this->addConfigStatusBanner($form);
    $this->addCustomSqlStatusWarnings($config);
    $this->addUnsafeCustomSqlStatusWarnings($config);

    $form['cron_settings'] = [
      '#type'  => 'details',
      '#title' => $this->t('Cron & Automation'),
      '#open'  => TRUE,
    ];
    $form['cron_settings']['run_on_cron'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Run aggregation on Cron'),
      '#default_value' => $config->get('run_on_cron') ?? FALSE,
      '#description'   => $this->t(
        'Requires that Drupal cron fires frequently enough. ' .
        'For sub-hourly schedules, configure an external cron job calling <code>drush cron</code> at the desired frequency.'
      ),
    ];
    $form['cron_settings']['trigger_on_request'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Also trigger on page requests'),
      '#default_value' => $config->get('trigger_on_request') ?? FALSE,
      '#description'   => $this->t(
        'Runs aggregation after the HTTP response is already sent to the visitor ' .
        '(PHP-FPM: zero page-load impact via <code>fastcgi_finish_request()</code>). ' .
        'The same schedule/interval applies. Use this when your hosting does not support ' .
        'frequent cron — any page visit after the scheduled time will trigger the run. ' .
        'Handled by the Interval Trigger module, which also limits how often requests ' .
        'check the schedule at all (<a href=":url">Interval Trigger settings</a>); a lock ' .
        'ensures only one concurrent execution regardless of traffic.',
        [':url' => Url::fromRoute('interval_trigger.settings')->toString()]
      ),
    ];
    $form['cron_settings']['trigger_on_index_complete'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Also trigger after Search API finishes indexing'),
      '#default_value' => $config->get('trigger_on_index_complete') ?? FALSE,
      '#description'   => $this->t(
        'Runs aggregation right after a Search API index finishes a full indexing pass ' .
        '(e.g. after a manual "Reindex" catches up, or cron indexing empties the queue) — ' .
        'instead of on a time-based schedule. Ignores the schedule/interval above by design: ' .
        'a finished reindex is a deliberate, infrequent signal, closer to the "Run Aggregation ' .
        'Now" button than to a passive timer. Only fires for a batch of more than one item ' .
        '(so routine single-item saves do not trigger it), and only for an index that at least ' .
        'one configured operation actually references.'
      ),
    ];

    $schedule_type = $config->get('schedule_type') ?? 'interval';
    $schedule_time = $config->get('schedule_time') ?? '02:00';
    $schedule_dow  = (int) ($config->get('schedule_day_of_week') ?? 1);

    $form['cron_settings']['schedule'] = [
      '#type'   => 'container',
      '#states' => [
        'visible' => [
          ['input[name="run_on_cron"]' => ['checked' => TRUE]],
          'or',
          ['input[name="trigger_on_request"]' => ['checked' => TRUE]],
        ],
      ],
    ];
    $schedule_form = &$form['cron_settings']['schedule'];

    $schedule_form['schedule_type'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Schedule type'),
      '#options'       => [
        'interval' => $this->t('Every interval'),
        'daily'    => $this->t('Daily at specific time'),
        'weekly'   => $this->t('Weekly at specific time'),
      ],
      '#default_value' => $schedule_type,
    ];

    $saved_interval = (int) ($config->get('schedule_interval') ?? 0);
    [$interval_count, $interval_unit] = $this->decomposeInterval($saved_interval);

    $schedule_form['interval_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['sas-interval-row']],
      '#states'     => [
        'visible' => ['select[name="schedule_type"]' => ['value' => 'interval']],
      ],
    ];
    $schedule_form['interval_wrapper']['interval_count'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Run every'),
      '#default_value' => $interval_count,
      '#min'           => 0,
      '#max'           => 10000,
      '#description'   => $this->t('Set to 0 to run on every cron invocation. Maximum: 10 000.'),
      '#attributes'    => ['style' => 'width:80px'],
    ];
    $schedule_form['interval_wrapper']['interval_unit'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Unit'),
      '#options'       => [
        'minutes' => $this->t('minutes'),
        'hours'   => $this->t('hours'),
        'days'    => $this->t('days'),
      ],
      '#default_value' => $interval_unit,
    ];

    $dow_options = [
      1 => $this->t('Monday'),
      2 => $this->t('Tuesday'),
      3 => $this->t('Wednesday'),
      4 => $this->t('Thursday'),
      5 => $this->t('Friday'),
      6 => $this->t('Saturday'),
      0 => $this->t('Sunday'),
    ];

    $schedule_form['schedule_time_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['sas-interval-row']],
      '#states'     => [
        'visible' => [
          'select[name="schedule_type"]' => [
            ['value' => 'daily'],
            ['value' => 'weekly'],
          ],
        ],
      ],
    ];
    $schedule_form['schedule_time_wrapper']['schedule_day_of_week'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Day of week'),
      '#options'       => $dow_options,
      '#default_value' => $schedule_dow,
      '#states'        => [
        'visible' => ['select[name="schedule_type"]' => ['value' => 'weekly']],
      ],
    ];
    $schedule_form['schedule_time_wrapper']['schedule_time'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Site time (HH:MM)'),
      '#default_value' => $schedule_time,
      '#size'          => 6,
      '#maxlength'     => 5,
      '#pattern'       => '([01]\d|2[0-3]):[0-5]\d',
      '#attributes'    => ['placeholder' => '02:00'],
      '#description'   => $this->t('24-hour time in the site time zone (@tz). The aggregation runs once after this time is reached; a missed run is caught up on the next cron run or visit.', [
        '@tz' => AggregationSchedule::siteTimezone($this->configFactory())->getName(),
      ]),
    ];

    if ($config->get('run_on_cron') || $config->get('trigger_on_request')) {
      $next_str = $this->computeNextRunLabel();
      if ($next_str !== '') {
        $schedule_form['next_run_info'] = [
          '#type'       => 'html_tag',
          '#tag'        => 'p',
          '#value'      => $this->t('Next eligible run: <strong>@time</strong>', ['@time' => $next_str]),
          '#attributes' => ['class' => ['description']],
        ];
      }
    }
    unset($schedule_form);

    $form['advanced'] = [
      '#type'  => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open'  => FALSE,
    ];
    $form['advanced']['time_limit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('PHP Time Limit (seconds)'),
      '#default_value' => $config->get('time_limit') ?? 240,
      '#min'           => 0,
    ];
    $form['advanced']['sql_time_limit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('SQL Query Timeout (ms)'),
      '#default_value' => $config->get('sql_time_limit') ?? 30000,
      '#min'           => 0,
    ];

    [$tables_data, $servers_data, $indexes_data] = $this->collectBuilderData();

    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    $form['builder_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'sas-builder-wrapper'],
    ];

    if (!empty($tables_data)) {
      $form['builder_wrapper']['ui'] = [
        '#theme'        => 'search_api_sql_aggregator_builder',
        '#tables_json'  => json_encode($tables_data, $flags),
        '#servers_json' => json_encode($servers_data, $flags),
        '#indexes_json' => json_encode($indexes_data, $flags),
        '#attached'     => ['library' => ['search_api_sql_aggregator/builder']],
      ];
    }
    else {
      $form['builder_wrapper']['msg'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('No Search API indexes found. Install and configure Search API first.'),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
    }

    $form['json_comparison'] = [
      '#type'  => 'details',
      '#title' => $this->t('JSON Configuration'),
      '#open'  => TRUE,
    ];
    $form['json_comparison']['cols'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['sas-json-cols']],
    ];
    $form['json_comparison']['cols']['saved'] = [
      '#type'       => 'textarea',
      '#title'      => $this->t('Saved'),
      '#value'      => $saved_json_str,
      '#attributes' => ['readonly' => 'readonly', 'class' => ['sas-json-readonly']],
      '#prefix'     => '<div class="sas-json-col">',
      '#suffix'     => '</div>',
    ];
    $form['json_comparison']['cols']['live'] = [
      '#type'       => 'textarea',
      '#title'      => $this->t('Live Preview'),
      '#attributes' => ['class' => ['sas-json-preview', 'sas-json-readonly']],
      '#prefix'     => '<div class="sas-json-col">',
      '#suffix'     => '</div>',
    ];

    $form['json_config'] = [
      '#type'          => 'textarea',
      '#default_value' => $saved_json_str,
      '#attributes'    => ['class' => ['sas-json-storage'], 'style' => 'display:none;'],
    ];

    $form['json_import'] = [
      '#type'        => 'details',
      '#title'       => $this->t('Import Configuration from JSON'),
      '#open'        => FALSE,
      '#description' => $this->t(
        'Paste a JSON configuration exported from another environment. <strong>This replaces the current configuration immediately.</strong>'
      ),
    ];
    $form['json_import']['import_json_text'] = [
      '#type'        => 'textarea',
      '#title'       => $this->t('JSON to import'),
      '#description' => $this->t('Must be a JSON array of operation objects, each with at minimum <code>type</code> and <code>target_table</code> keys.'),
      '#attributes'  => ['class' => ['sas-json-import-area']],
    ];
    $form['json_import']['import_btn'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Import JSON'),
      '#submit'                  => ['::importJsonHandler'],
      '#limit_validation_errors' => [['import_json_text']],
      '#button_type'             => 'secondary',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Save Configuration'),
      '#button_type' => 'primary',
    ];
    $form['actions']['run_saved'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Run Aggregation Now'),
      '#submit'      => ['::runSavedHandler'],
      '#button_type' => 'secondary',
    ];
    $form['actions']['validate_config'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Validate Config'),
      '#submit'                  => ['::validateConfigHandler'],
      '#limit_validation_errors' => [],
      '#button_type'             => 'secondary',
    ];
    $form['actions']['clear_config'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Clear Saved Config'),
      '#submit'                  => ['::clearSavedConfigHandler'],
      '#limit_validation_errors' => [],
      '#button_type'             => 'secondary',
      '#attributes'              => [
        'class'   => ['sas-btn-danger'],
        'onclick' => 'return confirm(' . json_encode((string) $this->t(
          'This will permanently delete the saved configuration. Continue?'
        )) . ');',
      ],
    ];

    $form['#attached']['library'][] = 'search_api_sql_aggregator/builder';

    $form['#attached']['drupalSettings']['searchApiSqlAggregator']['allowCustomSql'] = $this->userCanCustomSql();
    $form['#attached']['drupalSettings']['searchApiSqlAggregator']['allowUnsafeCustomSql'] = $this->userCanUnsafeCustomSql();

    $form['#attached']['drupalSettings']['searchApiSqlAggregator']['dbDriver'] = $this->aggregationManager->getDatabaseDriver();

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    if (\in_array($form_state->getValue('schedule_type'), ['daily', 'weekly'], TRUE)
      && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) $form_state->getValue('schedule_time')))) {
      $form_state->setErrorByName('schedule_time', $this->t('Enter the time as HH:MM in 24-hour format, from 00:00 to 23:59.'));
    }

    $intervalCount = (int) $form_state->getValue('interval_count');
    if ($intervalCount < 0) {
      $form_state->setErrorByName(
        'interval_count',
        $this->t('Interval count must be 0 or greater.')
      );
    }
    elseif ($intervalCount > 10000) {
      $form_state->setErrorByName(
        'interval_count',
        $this->t('Interval count must not exceed 10 000.')
      );
    }

    $timeLimit = (int) $form_state->getValue('time_limit');
    if ($timeLimit > 3600) {
      $form_state->setErrorByName(
        'time_limit',
        $this->t('PHP Time Limit must not exceed 3600 seconds (1 hour).')
      );
    }

    $sqlLimit = (int) $form_state->getValue('sql_time_limit');
    if ($sqlLimit > 300000) {
      $form_state->setErrorByName(
        'sql_time_limit',
        $this->t('SQL Query Timeout must not exceed 300 000 ms (5 minutes).')
      );
    }

    $json = $form_state->getValue('json_config');
    if (!empty($json) && !\is_string($json)) {
      $form_state->setErrorByName('json_config', $this->t('Invalid JSON in configuration field.'));
    }
    elseif (!empty($json)) {
      $data = json_decode($json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE || !\is_array($data)) {
        $form_state->setErrorByName('json_config', $this->t('Invalid JSON in configuration field.'));
      }
      else {
        if (\count($data) > OperationValidator::MAX_OPERATIONS) {
          $form_state->setErrorByName('json_config', $this->t(
            'Too many operations (@count). Maximum allowed: @max.',
            ['@count' => \count($data), '@max' => OperationValidator::MAX_OPERATIONS]
          ));
        }
        else {
          $allowed_types = ['aggregate', 'copy', 'null_reset', 'fill_from_union', 'priority_fill', 'custom_sql', 'custom_sql_unsafe'];
          foreach ($data as $i => $block) {
            if (!\is_array($block) || !isset($block['type']) || !\in_array($block['type'], $allowed_types, TRUE)) {
              $form_state->setErrorByName('json_config', $this->t(
                'Operation #@n has an unknown or missing type.',
                ['@n' => $i + 1]
              ));
              break;
            }
          }

          $custom_sql = $this->customSqlBlockIndexes($data);
          if (!empty($custom_sql) && !$this->userCanCustomSql()) {
            $form_state->setErrorByName('json_config', $this->t(
              'You do not have permission to configure Custom SQL operations (blocks: @n). Remove them or ask an administrator to grant the "SQL Aggregator: Custom SQL" permission.',
              ['@n' => implode(', ', $custom_sql)]
            ));
          }

          $unsafe_custom_sql = $this->unsafeCustomSqlBlockIndexes($data);
          if (!empty($unsafe_custom_sql) && !$this->userCanUnsafeCustomSql()) {
            $form_state->setErrorByName('json_config', $this->t(
              'You do not have permission to configure Unsafe Custom SQL operations (blocks: @n). Remove them or ask an administrator to grant the "SQL Aggregator: Unsafe Custom SQL" permission.',
              ['@n' => implode(', ', $unsafe_custom_sql)]
            ));
          }

          foreach ($this->aggregationManager->validateStructure($data) as $structErr) {
            $form_state->setErrorByName('json_config', $this->t('@msg', ['@msg' => $structErr['msg']]));
          }
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $count = (int) $form_state->getValue('interval_count');
    $unit  = $form_state->getValue('interval_unit');
    $interval_seconds = $this->composeInterval($count, $unit);

    $raw_time = trim((string) ($form_state->getValue('schedule_time') ?? '02:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $raw_time)) {
      $raw_time = '02:00';
    }

    $this->config('search_api_sql_aggregator.settings')
      ->set('time_limit',           (int)  $form_state->getValue('time_limit'))
      ->set('sql_time_limit',       (int)  $form_state->getValue('sql_time_limit'))
      ->set('json_config',          $form_state->getValue('json_config'))
      ->set('run_on_cron',          (bool) $form_state->getValue('run_on_cron'))
      ->set('trigger_on_request',   (bool) $form_state->getValue('trigger_on_request'))
      ->set('trigger_on_index_complete', (bool) $form_state->getValue('trigger_on_index_complete'))
      ->set('schedule_type',        (string) ($form_state->getValue('schedule_type') ?? 'interval'))
      ->set('schedule_interval',    $interval_seconds)
      ->set('schedule_time',        $raw_time)
      ->set('schedule_day_of_week', (int)  $form_state->getValue('schedule_day_of_week'))
      ->save();

    $saved_blocks = AggregationManager::decodeBlocks($form_state->getValue('json_config'));
    if (\is_array($saved_blocks)) {
      $this->auditCustomSqlSave($saved_blocks, 'settings form');
      $this->auditUnsafeCustomSqlSave($saved_blocks, 'settings form');
    }

    $valid = $this->runValidationAndSave();
    if (!$valid) {
      $this->messenger()->addWarning($this->t(
        'Configuration saved, but validation failed — it is now in DRAFT mode and will not run on cron. Review the errors shown above and save again.'
      ));
    }

    parent::submitForm($form, $form_state);
  }

  public function clearSavedConfigHandler(array &$form, FormStateInterface $form_state) {
    $this->config('search_api_sql_aggregator.settings')
      ->set('json_config', '[]')
      ->set('config_valid', FALSE)
      ->set('config_validation_errors', [])
      ->save();
    $this->messenger()->addWarning($this->t('Saved configuration has been cleared.'));
  }

  public function runSavedHandler(array &$form, FormStateInterface $form_state) {
    $config = $this->config('search_api_sql_aggregator.settings');

    if (!$config->get('config_valid')) {
      $this->messenger()->addWarning($this->t(
        'Configuration is in DRAFT mode (validation failed). Fix the errors shown above and validate before running.'
      ));
      return;
    }

    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));

    if (empty($blocks) || !\is_array($blocks)) {
      $this->messenger()->addWarning($this->t('No operations configured. Save a configuration first.'));
      return;
    }

    $custom_sql = $this->customSqlBlockIndexes($blocks);
    if (!empty($custom_sql) && !$this->userCanCustomSql()) {
      $this->messenger()->addError($this->t(
        'This configuration contains Custom SQL (blocks: @n) and you do not have permission to run it.',
        ['@n' => implode(', ', $custom_sql)]
      ));
      return;
    }

    $unsafe_custom_sql = $this->unsafeCustomSqlBlockIndexes($blocks);
    if (!empty($unsafe_custom_sql) && !$this->userCanUnsafeCustomSql()) {
      $this->messenger()->addError($this->t(
        'This configuration contains Unsafe Custom SQL (blocks: @n) and you do not have permission to run it.',
        ['@n' => implode(', ', $unsafe_custom_sql)]
      ));
      return;
    }

    try {
      $run = $this->aggregationManager->processAggregation(
        $blocks,
        (int) $config->get('time_limit'),
        (int) $config->get('sql_time_limit'),
        'manual',
        (string) ($config->get('custom_sql_signature') ?? ''),
        (string) ($config->get('custom_sql_unsafe_signature') ?? '')
      );
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }

    // processAggregation() reports failures in its result, not exceptions.
    if ($run === NULL) {
      $this->messenger()->addWarning($this->t('Not started: another aggregation run is still in progress.'));
    }
    elseif ($run['status'] === 'SUCCESS') {
      $this->messenger()->addStatus($this->t('Operations completed successfully.'));
    }
    elseif ($run['status'] === 'WARNING') {
      $this->messenger()->addWarning($this->t('Operations completed with errors — see the run log below.'));
    }
    else {
      $this->messenger()->addError($this->t('The run failed — see the run log below.'));
    }
  }

  public function validateConfigHandler(array &$form, FormStateInterface $form_state) {
    $config = $this->config('search_api_sql_aggregator.settings');
    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));

    if (empty($blocks) || !\is_array($blocks)) {
      $this->messenger()->addWarning($this->t('No operations configured to validate.'));
      return;
    }

    $results   = $this->aggregationManager->validateOperations($blocks);
    $hasErrors = FALSE;
    $errors    = [];

    foreach ($results as $r) {
      if ($r['status'] === 'error') {
        $this->messenger()->addError($this->t('@target: @msg', [
          '@target' => $r['target'],
          '@msg'    => $r['msg'],
        ]));
        $errors[]   = $r['target'] . ': ' . $r['msg'];
        $hasErrors  = TRUE;
      }
      else {
        $this->messenger()->addStatus($this->t('@target: @msg', [
          '@target' => $r['target'],
          '@msg'    => $r['msg'],
        ]));
      }
    }

    if (!$hasErrors) {
      $this->messenger()->addStatus($this->t(
        'All @n operation(s) validated successfully.', ['@n' => \count($results)]
      ));
    }

    $config->set('config_valid', !$hasErrors)
           ->set('config_validation_errors', $errors)
           ->save();
  }

  public function importJsonHandler(array &$form, FormStateInterface $form_state) {
    $json = trim($form_state->getValue('import_json_text') ?? '');

    if (empty($json)) {
      $this->messenger()->addError($this->t('JSON input is empty.'));
      return;
    }

    $data = json_decode($json, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || !\is_array($data)) {
      $this->messenger()->addError($this->t('Invalid JSON: @error', ['@error' => json_last_error_msg()]));
      return;
    }

    $allowed_types = ['aggregate', 'copy', 'null_reset', 'fill_from_union', 'priority_fill', 'custom_sql', 'custom_sql_unsafe'];
    foreach ($data as $i => $block) {
      if (!\is_array($block)) {
        $this->messenger()->addError($this->t('Entry @n is not an object.', ['@n' => $i + 1]));
        return;
      }
      if (!isset($block['type']) || !\in_array($block['type'], $allowed_types, TRUE)) {
        $this->messenger()->addError($this->t(
          'Entry @n has an unknown or missing operation type.',
          ['@n' => $i + 1]
        ));
        return;
      }
      if (!\in_array($block['type'], ['custom_sql', 'custom_sql_unsafe'], TRUE) && !\array_key_exists('target_table', $block)) {
        $this->messenger()->addError($this->t(
          'Entry @n is missing required key (target_table).',
          ['@n' => $i + 1]
        ));
        return;
      }
    }

    $custom_sql = $this->customSqlBlockIndexes($data);
    if (!empty($custom_sql) && !$this->userCanCustomSql()) {
      $this->messenger()->addError($this->t(
        'Import refused: entries @n are Custom SQL and you do not have permission to configure them.',
        ['@n' => implode(', ', $custom_sql)]
      ));
      return;
    }

    $unsafe_custom_sql = $this->unsafeCustomSqlBlockIndexes($data);
    if (!empty($unsafe_custom_sql) && !$this->userCanUnsafeCustomSql()) {
      $this->messenger()->addError($this->t(
        'Import refused: entries @n are Unsafe Custom SQL and you do not have permission to configure them.',
        ['@n' => implode(', ', $unsafe_custom_sql)]
      ));
      return;
    }

    $this->config('search_api_sql_aggregator.settings')
      ->set('json_config', json_encode($data))
      ->save();

    $this->auditCustomSqlSave($data, 'JSON import');
    $this->auditUnsafeCustomSqlSave($data, 'JSON import');

    $valid = $this->runValidationAndSave();
    $this->messenger()->addStatus($this->t('Imported @n block(s) successfully.', ['@n' => \count($data)]));
    if (!$valid) {
      $this->messenger()->addWarning($this->t(
        'Imported configuration failed validation and is in DRAFT mode — it will not run on cron.'
      ));
    }
    $form_state->setRebuild(TRUE);
  }

  protected function computeNextRunLabel(): string {
    $schedule = AggregationSchedule::fromConfig($this->configFactory());
    $last_run = $this->state->get('search_api_sql_aggregator.last_run');
    $now      = $this->time->getCurrentTime();
    $next     = $schedule->nextRun((int) ($last_run['timestamp'] ?? 0), $now);

    if ($next === NULL) {
      return (string) $this->config('search_api_sql_aggregator.settings')->get('schedule_type') === 'interval'
        ? (string) $this->t('on every cron run or visit')
        : '';
    }
    if ($next <= $now) {
      return (string) $this->t('immediately (on the next cron run or visit)');
    }
    return $this->dateFormatter->format($next, 'medium', '', $schedule->getTimezone()->getName());
  }

  protected function decomposeInterval(int $seconds): array {
    if ($seconds <= 0)              return [0, 'minutes'];
    if ($seconds % 86400 === 0)     return [$seconds / 86400, 'days'];
    if ($seconds % 3600 === 0)      return [$seconds / 3600,  'hours'];
    if ($seconds % 60 === 0)        return [$seconds / 60,    'minutes'];
    return [$seconds, 'minutes'];
  }

  protected function composeInterval(int $count, string $unit): int {
    if ($count <= 0) return 0;
    $multipliers = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
    return $count * ($multipliers[$unit] ?? 60);
  }

  protected function collectBuilderData(): array {
    $tables_data  = $this->aggregationManager->getSchemaData();
    $servers_data = [];
    $indexes_data = [];

    foreach ($tables_data as $t) {
      $sid = $t['server_id'];
      $iid = $t['index_id'];
      if (!isset($servers_data[$sid])) {
        $servers_data[$sid] = $t['server_label'] ?? $sid;
      }
      if (!isset($indexes_data[$iid])) {
        $indexes_data[$iid] = [
          'label'     => $t['index_label'],
          'server_id' => $sid,
        ];
      }
    }

    return [$tables_data, $servers_data, $indexes_data];
  }

  protected function addLastRunReport(array &$form) {
    $last_run = $this->state->get('search_api_sql_aggregator.last_run');
    if (\is_array($last_run) && !empty($last_run)) {
      $timestamp = (int) ($last_run['timestamp'] ?? 0);
      $form['last_run_report'] = [
        '#theme'      => 'search_api_sql_aggregator_last_run',
        '#status'     => $last_run['status'] ?? 'ERROR',
        '#date'       => $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'medium') : $this->t('unknown'),
        '#total_time' => $last_run['total_time'] ?? '',
        '#trigger'    => $last_run['trigger'] ?? NULL,
        '#logs'       => \is_array($last_run['log'] ?? NULL) ? $last_run['log'] : [],
        '#weight'     => -100,
      ];
    }

    $system_cron_last = $this->state->get('system.cron_last');
    if ($system_cron_last) {
      $form['system_cron_info'] = [
        '#type'       => 'html_tag',
        '#tag'        => 'p',
        '#value'      => $this->t('Drupal system cron last ran: <strong>@time</strong>', [
          '@time' => $this->dateFormatter->format((int) $system_cron_last, 'medium'),
        ]),
        '#attributes' => ['class' => ['description', 'sas-system-cron-info']],
        '#weight'     => -99,
      ];
    }
  }

  protected function addRunHistoryReport(array &$form): void {
    $history = $this->state->get('search_api_sql_aggregator.run_history');
    if (!\is_array($history) || empty($history)) {
      return;
    }

    $known_triggers = ['manual', 'cron', 'request', 'index'];
    $runs = [];
    foreach ($history as $entry) {
      if (!\is_array($entry)) {
        continue;
      }
      $ts = (int) ($entry['timestamp'] ?? 0);
      $runs[] = [
        'date'       => $ts > 0 ? $this->dateFormatter->format($ts, 'short') : $this->t('unknown'),
        'status'     => $entry['status'] ?? 'ERROR',
        'trigger'    => \in_array($entry['trigger'] ?? '', $known_triggers, TRUE) ? $entry['trigger'] : 'manual',
        'ops'        => (int) ($entry['ops'] ?? 0),
        'errors'     => (int) ($entry['errors'] ?? 0),
        'total_time' => $entry['total_time'] ?? NULL,
      ];
    }

    if (empty($runs)) {
      return;
    }

    $form['run_history'] = [
      '#type'   => 'details',
      '#title'  => $this->t('Run history (@n)', ['@n' => \count($runs)]),
      '#open'   => FALSE,
      '#weight' => -95,
    ];
    $form['run_history']['table'] = [
      '#theme' => 'search_api_sql_aggregator_run_history',
      '#runs'  => $runs,
    ];
  }

  protected function addConfigStatusBanner(array &$form): void {
    $config = $this->config('search_api_sql_aggregator.settings');
    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));

    if (empty($blocks)) {
      return;
    }

    $form['config_status'] = [
      '#theme'  => 'search_api_sql_aggregator_config_status',
      '#valid'  => $config->get('config_valid'),
      '#errors' => $config->get('config_validation_errors') ?? [],
      '#weight' => -90,
    ];
  }

  protected function addCustomSqlStatusWarnings($config): void {
    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));
    if (empty($blocks) || !\is_array($blocks) || empty($this->customSqlBlockIndexes($blocks))) {
      return;
    }
    if (!$this->aggregationManager->customSqlRunKeyMatches()) {
      $this->messenger()->addWarning($this->t(
        'This configuration has Custom SQL block(s), but this server has not been configured to ' .
        'execute them ($settings[\'search_api_sql_aggregator_custom_sql_run_key\'] is missing or ' .
        'does not match). They will not run until someone with server access sets it — see the module help page.'
      ));
    }
    if ((string) ($config->get('custom_sql_signature') ?? '') === '') {
      $this->messenger()->addWarning($this->t(
        'This configuration has Custom SQL block(s) that are not currently signed and will not run. ' .
        'This usually means they were added or changed while the settings.php creation key ' .
        '($settings[\'search_api_sql_aggregator_custom_sql_create_key\']) was not present — add it and ' .
        'save this form again to authorise them.'
      ));
    }
  }

  protected function addUnsafeCustomSqlStatusWarnings($config): void {
    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));
    if (empty($blocks) || !\is_array($blocks) || empty($this->unsafeCustomSqlBlockIndexes($blocks))) {
      return;
    }
    $handler = $this->aggregationManager->getUnsafeCustomSqlHandler();
    if (!$handler) {
      $this->messenger()->addWarning($this->t(
        'This configuration has Unsafe Custom SQL block(s), but the "Unsafe Custom SQL" submodule ' .
        '(search_api_sql_aggregator_unsafe) is not installed on this site — they cannot run at all until ' .
        'someone installs it.'
      ));
      return;
    }
    if (!$handler->runKeyMatches()) {
      $this->messenger()->addWarning($this->t(
        'This configuration has Unsafe Custom SQL block(s), but this server has not been configured to ' .
        'execute them ($settings[\'search_api_sql_aggregator_unsafe_custom_sql_run_key\'] is missing or ' .
        'does not match). They will not run until someone with server access sets it — see the module help page.'
      ));
    }
    if ((string) ($config->get('custom_sql_unsafe_signature') ?? '') === '') {
      $this->messenger()->addWarning($this->t(
        'This configuration has Unsafe Custom SQL block(s) that are not currently signed and will not ' .
        'run. This usually means they were added or changed while the settings.php creation key ' .
        '($settings[\'search_api_sql_aggregator_unsafe_custom_sql_create_key\']) was not present — add ' .
        'it and save this form again to authorise them.'
      ));
    }
  }

  protected function runValidationAndSave(): bool {
    $config = $this->config('search_api_sql_aggregator.settings');
    $blocks = AggregationManager::decodeBlocks($config->get('json_config'));

    if (empty($blocks) || !\is_array($blocks)) {
      $config->set('config_valid', FALSE)
             ->set('config_validation_errors', [])
             ->set('custom_sql_signature', '')
             ->set('custom_sql_unsafe_signature', '')
             ->save();
      return FALSE;
    }

    $results = $this->aggregationManager->validateOperations($blocks);
    $errors  = [];
    foreach ($results as $r) {
      if ($r['status'] === 'error') {
        $errors[] = $r['target'] . ': ' . $r['msg'];
      }
    }
    $valid = empty($errors);

    $previousSignature = (string) ($config->get('custom_sql_signature') ?? '');
    $signature = $this->computeCustomSqlSignatureForSave($blocks, $valid, $previousSignature);

    $previousUnsafeSignature = (string) ($config->get('custom_sql_unsafe_signature') ?? '');
    $unsafeSignature = $this->computeUnsafeSignatureForSave($blocks, $valid, $previousUnsafeSignature);

    $config->set('config_valid', $valid)
           ->set('config_validation_errors', $errors)
           ->set('custom_sql_signature', $signature)
           ->set('custom_sql_unsafe_signature', $unsafeSignature)
           ->save();
    return $valid;
  }

  protected function computeCustomSqlSignatureForSave(array $blocks, bool $valid, string $previouslyStoredSignature): string {
    if (!$valid) {
      return '';
    }
    $freshlyComputed = $this->aggregationManager->computeCustomSqlSignature($blocks);
    if (\hash_equals($previouslyStoredSignature, $freshlyComputed)) {
      return $previouslyStoredSignature;
    }
    return ($this->userCanCustomSql() && $this->aggregationManager->customSqlCreateKeyMatches())
      ? $freshlyComputed
      : '';
  }

  protected function computeUnsafeSignatureForSave(array $blocks, bool $valid, string $previouslyStoredSignature): string {
    if (!$valid) {
      return '';
    }
    $handler = $this->aggregationManager->getUnsafeCustomSqlHandler();
    if (!$handler) {
      return '';
    }
    $freshlyComputed = $handler->computeSignature($blocks);
    if (\hash_equals($previouslyStoredSignature, $freshlyComputed)) {
      return $previouslyStoredSignature;
    }
    return ($this->userCanUnsafeCustomSql() && $handler->createKeyMatches())
      ? $freshlyComputed
      : '';
  }

}
