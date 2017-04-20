<?php

namespace Drupal\mindbody;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Mindbody Service Manager.
 *
 * @package Drupal\mindbody
 */
class MindbodyClient implements MindbodyClientInterface {

  /**
   * Debug mode.
   *
   * @var bool
   */
  public $debug;

  /**
   * Api hostname.
   *
   * @var string
   */
  public $hostname;

  /**
   * Config Factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Credentials.
   *
   * @var array
   */
  protected $credentials;

  /**
   * Client.
   *
   * @var \SoapClient
   */
  protected $client;

  /**
   * Logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * MindbodyServiceManager constructor.
   *
   * @param ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param LoggerChannelFactoryInterface $logger_factory
   *   Logger factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('mindbody');
  }

  /**
   * Set credentials.
   */
  protected function setCredentials() {
    $settings = $this->configFactory->get('mindbody.settings');

    // Check whether the module is configured.
    foreach ($settings->getRawData() as $item_name => $value) {
      if (empty($value)) {
        $message = "Mindbody API credentials are not configured. \"$item_name\" is empty.";
        $this->logger->error($message);
        throw new MindbodyException($message);
      }
    }

    $this->credentials = [
      'SourceName' => $settings->get('sourcename'),
      'Password' => $settings->get('password'),
      'SiteIDs' => [$settings->get('site_id')],
    ];
  }

  /**
   * Set up a client.
   *
   * @param string $service
   *   Service name.
   */
  protected function setUpClient($service) {
    $endpointUrl = "https://" . $this->hostname . "/0_5/" . $service . ".asmx";
    $wsdlUrl = $endpointUrl . "?wsdl";

    $option = [];
    if ($this->debug) {
      $option = [
        'trace' => 1
      ];
    }

    $this->client = new \SoapClient($wsdlUrl, $option);
    $this->client->__setLocation($endpointUrl);
  }

  /**
   * {@inheritdoc}
   */
  public function call($service, $endpoint, array $params = []) {
    $this->setCredentials();

    // @TODO: It (and below in "try") should be removed after MindBody Team fix
    // response and it will contain EmpID in parsed structure.
    if ($service == 'DataService' && $endpoint == 'FunctionDataXml' && isset($params['FunctionName']) && $params['FunctionName'] == 'YMCAGTC_GetEmpID') {
      $this->debug = TRUE;
    }
    $this->setUpClient($service);

    try {
      $result = $this->client->{$endpoint}($this->getMindbodyParams($params));

      // @TODO: It (and above) should be removed after MindBody Team fix.
      // Debug mode may be available for other services and
      // we need SoapClient in result only for FunctionDataXml request.
      if (!empty($this->debug) && $service == 'DataService' && $endpoint == 'FunctionDataXml' && isset($params['FunctionName']) && $params['FunctionName'] == 'YMCAGTC_GetEmpID') {
        $result->SoapClient = $this->client;
      }

      // Check whether the results are OK.
      $property = $endpoint . 'Result';
      if ($result->{$property}->ErrorCode != 200) {
        $msg = 'Error while getting the results. Status: %status';
        $this->logger->error($msg, ['%status' => $result->{$property}->Status]);
      }

      return $result;
    }
    catch (\Exception $e) {
      $params_log = serialize($params);
      $message = $e->getMessage();
      $msg = "Failed to call MindBody with message: $message for service: $service for the endpoint $endpoint with parameters: $params_log";
      throw new MindbodyException($msg);
    }
  }

  /**
   * Generate params.
   *
   * @param array $additions
   *   Additions.
   *
   * @return array
   *   Params.
   */
  protected function getMindbodyParams(array $additions) {
    $params['SourceCredentials'] = $this->credentials;
    $params['XMLDetail'] = 'Full';
    $params['PageSize'] = NULL;
    $params['CurrentPageIndex'] = NULL;
    if (empty($additions['Fields'])) {
      $params['Fields'] = NULL;
    }

    return ['Request' => array_merge($params, $additions)];
  }

}
