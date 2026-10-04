<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;

trait StringTranslationContainerTrait {

  protected function setUpStringTranslationContainer(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

}
