<?php

namespace Drupal\personify_mindbody_sync;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactory;
use GuzzleHttp\Client;

/**
 * Class PersonifyMindbodySyncFetcher.
 *
 * @package Drupal\personify_mindbody_sync
 */
abstract class PersonifyMindbodySyncFetcherBase implements PersonifyMindbodySyncFetcherInterface {

  /**
   * PersonifyMindbodySyncWrapper definition.
   *
   * @var PersonifyMindbodySyncWrapper
   */
  protected $wrapper;

  /**
   * Http client.
   *
   * @var Client
   */
  protected $client;

  /**
   * Config factory.
   *
   * @var ConfigFactory
   */
  protected $config;

  /**
   * Logger channel.
   *
   * @var LoggerChannel
   */
  protected $logger;

  /**
   * PersonifyMindbodySyncFetcher constructor.
   *
   * @param PersonifyMindbodySyncWrapper $wrapper
   *   Wrapper.
   * @param Client $client
   *   Http client.
   * @param ConfigFactory $config
   *   Config factory.
   * @param LoggerChannelFactory $logger_factory
   *   Config factory.
   */
  public function __construct(PersonifyMindbodySyncWrapper $wrapper, Client $client, ConfigFactory $config, LoggerChannelFactory $logger_factory) {
    $this->wrapper = $wrapper;
    $this->client = $client;
    $this->config = $config;
    $this->logger = $logger_factory->get(PersonifyMindbodySyncWrapper::CHANNEL);
  }

  /**
   * Get Personify orders.
   *
   * @param string $lastDataAccessDate
   *   Example: 2000-01-01T11:20:00.
   *
   * @return array
   *   An array of Personify orders.
   */
  protected function getData($lastDataAccessDate) {
    $orders = [];
    $settings = $this->config->get('ymca_personify.settings');

    $options = [
      'json' => [
        'CL_MindBodyCustomerOrderInput' => [
          'LastDataAccessDate' => $lastDataAccessDate,
        ],
      ],
      'headers' => [
        'Content-Type' => 'application/json;charset=utf-8',
      ],
      'auth' => [
        $settings->get('customer_orders_username'),
        $settings->get('customer_orders_password'),
      ],
    ];

    try {
      $response = $this->client->request('POST', $settings->get('customer_orders_endpoint'), $options);
      if ($response->getStatusCode() == '200') {
        $body = $response->getBody();
        $data = json_decode($body->getContents());
        foreach ($data->MindBodyCustomerOrderDetail as $order) {
          $orders[] = $order;
        }
      }
      else {
        $msg = 'Got %code response from Personify: %msg';
        $this->logger->error(
          $msg,
          [
            '%code' => $response->getStatusCode(),
            '%msg' => $response->getReasonPhrase(),
          ]
        );
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get Personify data: %msg', ['%msg' => $e->getMessage()]);
      throw new \Exception('Personify is down.');
    }

    return $orders;
  }

}
