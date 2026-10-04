<?php

namespace Drupal\search_api_sql_aggregator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\search_api_sql_aggregator\Service\AggregationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KeysController extends ControllerBase {

  /**
   * @var \Drupal\search_api_sql_aggregator\Service\AggregationManager
   */
  protected $aggregationManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->aggregationManager = $container->get('search_api_sql_aggregator.manager');
    return $instance;
  }

  public function build(): array {
    $build = [];

    // help.page only exists with the help module, which is not a dependency.
    $helpUrl = $this->moduleHandler()->moduleExists('help')
      ? Url::fromRoute('help.page', ['name' => 'search_api_sql_aggregator'])->toString()
      : Url::fromRoute('search_api_sql_aggregator.settings')->toString();
    $build['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Copy the exact line(s) below into settings.php on this server. Each value is a deterministic HMAC of the site\'s hash_salt — recomputing it here always gives the same result, nothing is generated or stored. Placing a value in settings.php requires filesystem/deploy access; that access, not knowledge of the value, is the actual gate. See the <a href=":help">module help page</a> for what each key does.',
        [':help' => $helpUrl]
      ) . '</p>',
    ];

    $rows = [];

    if ($this->currentUser()->hasPermission('use sql aggregator custom sql')) {
      $rows[] = $this->buildRow(
        $this->t('Custom SQL — creation key'),
        'search_api_sql_aggregator_custom_sql_create_key',
        AggregationManager::customSqlCreateKey(),
        $this->aggregationManager->customSqlCreateKeyMatches(),
        $this->t('Temporary — keep in settings.php only while authoring a new/changed Custom SQL block, then remove it.')
      );
      $rows[] = $this->buildRow(
        $this->t('Custom SQL — run key'),
        'search_api_sql_aggregator_custom_sql_run_key',
        AggregationManager::customSqlRunKey(),
        $this->aggregationManager->customSqlRunKeyMatches(),
        $this->t('Permanent — required for any already-approved Custom SQL block to execute at all.')
      );
    }

    $unsafeHandler = $this->aggregationManager->getUnsafeCustomSqlHandler();
    if ($unsafeHandler && $this->currentUser()->hasPermission('use sql aggregator unsafe custom sql')) {
      $rows[] = $this->buildRow(
        $this->t('Unsafe Custom SQL — creation key'),
        'search_api_sql_aggregator_unsafe_custom_sql_create_key',
        $unsafeHandler->createKey(),
        $unsafeHandler->createKeyMatches(),
        $this->t('Temporary — keep in settings.php only while authoring a new/changed Unsafe Custom SQL block, then remove it.')
      );
      $rows[] = $this->buildRow(
        $this->t('Unsafe Custom SQL — run key'),
        'search_api_sql_aggregator_unsafe_custom_sql_run_key',
        $unsafeHandler->runKey(),
        $unsafeHandler->runKeyMatches(),
        $this->t('Permanent — required for any already-approved Unsafe Custom SQL block to execute at all.')
      );
    }
    elseif (!$unsafeHandler && $this->currentUser()->hasPermission('use sql aggregator unsafe custom sql')) {
      $build['unsafe_not_installed'] = [
        '#markup' => '<p>' . $this->t(
          'You hold the Unsafe Custom SQL permission, but the "Unsafe Custom SQL" submodule (search_api_sql_aggregator_unsafe) is not installed on this site, so there are no keys to show for it.'
        ) . '</p>',
      ];
    }

    $build['keys'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Key'),
        $this->t('Paste into settings.php'),
        $this->t('Currently'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('You do not hold the Custom SQL or Unsafe Custom SQL permission, so there is nothing to show here.'),
    ];

    return $build;
  }

  private function buildRow($label, string $setting, string $value, bool $active, $description): array {
    $snippet = "\$settings['{$setting}'] = '{$value}';";
    return [
      'data' => [
        [
          'data' => ['#markup' => '<strong>' . $label . '</strong><br><small>' . $description . '</small>'],
        ],
        [
          'data' => ['#markup' => '<code style="white-space: pre-wrap; word-break: break-all;">' . htmlspecialchars($snippet, ENT_QUOTES) . '</code>'],
        ],
        [
          'data' => [
            '#markup' => $active
              ? '<strong style="color:#2e7d32;">✅ ' . $this->t('Active (matches settings.php)') . '</strong>'
              : '<span style="color:#a82e1e;">⚠ ' . $this->t('Not active') . '</span>',
          ],
        ],
      ],
    ];
  }

}
