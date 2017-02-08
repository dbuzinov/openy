<?php

namespace Drupal\openy_socrates;

/**
 * Class OpenySocratesFacade.
 *
 * @package Drupal\openy_socrates
 *
 * @method mixed getLocationLongtitude(array $args)
 * @method mixed getLocationLatitude(array $args)
 *
 */
class OpenySocratesFacade {

  /**
   * @var array
   */
  private $services;

  /**
   * Magic method call
   * @param $name
   * @param $arguments
   * @return string
   * @throws \Drupal\openy_socrates\OpenySocratesException
   */
  public function __call($name, $arguments) {
    if (isset($this->services[$name])) {
      // Get array of possible variants for the call.
      $calls_data = $this->services[$name];
      // Reset key values for easier access.
      $reset_keys_data = array_values($calls_data);
      // Proceed with first, highest priority item.
      $service = array_shift($reset_keys_data);
      return call_user_func_array([$service, $name], $arguments);
    }
    else {
      throw new OpenySocratesException(
        sprintf('Method %s not implemented yet.', $name)
      );
    }
  }

  /**
   * Setter for services tagged with 'openy_data_service' tag.
   * @param array $services
   */
  public function collectDataServices($services) {
    $todo_services = [];
    foreach ($services as $priority => $allservices) {
      /**
       * @var integer $key
       * @var OpenyDataServiceInterface $service
       */
      foreach ($allservices as $key => $service) {
        foreach ($service->addDataServices($todo_services) as $method) {
          $this->services[$method][$priority] = $service;
          krsort($this->services[$method]);
        }
      }
    }
  }
}
