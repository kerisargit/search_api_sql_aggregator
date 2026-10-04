<?php

namespace Drupal\Tests\search_api_sql_aggregator\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api_sql_aggregator\Controller\KeysController;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @group search_api_sql_aggregator
 * @group search_api_sql_aggregator_kernel
 */
class KeysControllerTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'interval_trigger',
    'search_api_sql_aggregator',
  ];

  public function testBuildsWithoutHelpModule(): void {
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], ['administer sql aggregator', 'use sql aggregator custom sql']);

    $build = KeysController::create($this->container)->build();

    $this->assertStringContainsString('/admin/config/search/search-api/sql-aggregator', (string) $build['intro']['#markup']);
    $this->assertCount(2, $build['keys']['#rows']);
  }

}
