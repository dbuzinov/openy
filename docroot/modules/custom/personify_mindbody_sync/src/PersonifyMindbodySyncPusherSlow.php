<?php

namespace Drupal\personify_mindbody_sync;

use Drupal\mindbody\MindbodyException;

/**
 * Class PersonifyMindbodySyncPusherSlow.
 *
 * @package Drupal\personify_mindbody_sync
 */
class PersonifyMindbodySyncPusherSlow extends PersonifyMindbodySyncPusherBase {

  /**
   * {@inheritdoc}
   */
  public function push() {
    $this->pushClientsSingle($this->debug);
    parent::pushOrders($this->debug);
  }

  /**
   * Push clients to MindBody one by one.
   *
   * @param bool $debug
   *   Mode.
   */
  private function pushClientsSingle($debug = TRUE) {
    if (!parent::filerOutClients()) {
      return;
    }

    if ($debug) {
      $clients = [];
      $key = key($this->clientIds);
      $clients[$key] = $this->clientIds[$key];
      $this->clientIds = $clients;
    }

    // Let's push new clients to MindBody.
    foreach ($this->clientIds as $client_id => $client) {
      try {
        $result = $this->client->call(
          'ClientService',
          'AddOrUpdateClients',
          ['Clients' => [$client]],
          FALSE
        );
      }
      catch (MindbodyException $e) {
        $this->updateStatusByClients([$client_id], $e->getMessage());
        $msg = 'Failed to push (exception) single client: %error';
        $this->logger->critical($msg, ['%error' => $e->getMessage()]);
        // Continue with the next client.
        continue;
      }
      if ($result->AddOrUpdateClientsResult->ErrorCode == 200) {
        $response = $result->AddOrUpdateClientsResult->Clients->Client;
        $this->updateClientData($client_id, $response);
      }
      else {
        // Something went wrong.
        // To reproduce create set wrong phone number, for example.
        $status = $result->AddOrUpdateClientsResult->Clients->Client->Messages->string;
        $this->updateStatusByClients([$client_id], $status);
        $msg = 'Failed to push single client: %error';
        $this->logger->critical($msg, ['%error' => serialize($result)]);
      }
    }
  }

}
