<?php

namespace Drupal\Tests\search_api_sql_aggregator\Kernel;

use Drupal\search_api_sql_aggregator\Service\OperationValidator;

/**
 * Validator that skips the Search API backend preflight.
 *
 * For Kernel tests about triggers and forms, which run without Search API.
 */
class AlwaysAvailableOperationValidator extends OperationValidator {

  public function assertSqlIndexesAvailable(): void {}

}
