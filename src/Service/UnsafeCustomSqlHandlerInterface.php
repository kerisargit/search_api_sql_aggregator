<?php

namespace Drupal\search_api_sql_aggregator\Service;

interface UnsafeCustomSqlHandlerInterface {

  public function validate(array $op): void;

  public function execute(array $op): array;

  public function computeSignature(array $blocks): string;

  public function createKey(): string;

  public function runKey(): string;

  public function createKeyMatches(): bool;

  public function runKeyMatches(): bool;

}
