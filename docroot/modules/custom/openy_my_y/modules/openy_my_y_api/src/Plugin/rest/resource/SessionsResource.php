<?php

namespace Drupal\openy_my_y_api\Plugin\rest\resource;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides Sessions Resource.
 *
 * @RestResource(
 *   id = "openy_my_y_api_sessions",
 *   label = @Translation("Sessions"),
 *   uri_paths = {
 *     "canonical" = "/openy_my_y/sessions/{uid}"
 *   }
 * )
 */
class SessionsResource extends ResourceBase {

  /**
   * Responds to entity GET requests.
   * @return \Drupal\rest\ResourceResponse
   */
  public function get($uid = NULL) {
    /** @var \Drupal\mindbody_cache_proxy\MindbodyCacheProxy $client */
    $client = \Drupal::service('mindbody_cache_proxy.client');
    $credentials = \Drupal::configFactory()->get('mindbody.settings');

    $request = [
      'UserCredentials' => [
        'Username' => $credentials->get('user_name'),
        'Password' => $credentials->get('user_password'),
        'SiteIDs' => [$credentials->get('site_id')],
      ],
      'ClientID' => $uid,
      'ShowActiveOnly' => true,
      // @todo As per MB UI - ClassID is 0 by default
      'ClassID' => 0,
      // @todo Check if we need other program IDs to be included.
      'ProgramIDs' => [2],
    ];

    $results = [];

    try {
      $result = $client->call('ClientService', 'GetClientServices', $request, FALSE);
    }
    catch (\Exception $e) {
      return new ResourceResponse([
        'status' => FALSE,
        'message' => $e->getMessage(),
        'timestamp' => \Drupal::time()->getRequestTime(),
        'results' => [],
      ]);
    }

    $sessions = $result->GetClientServicesResult->ClientServices;

    // In case if there is no appointments at all.
    if (!isset($sessions->ClientService)) {
      $response = new ResourceResponse([
        'status' => TRUE,
        'message' => '',
        'timestamp' => \Drupal::time()->getRequestTime(),
        'results' => [],
      ]);

      $response->headers->add(
        [
          'Access-Control-Allow-Origin' => 'http://0.0.0.0:8080',
          'Access-Control-Allow-Methods' => "POST, GET, OPTIONS, PATCH, DELETE",
          'Access-Control-Allow-Headers' => "Authorization, X-CSRF-Token, Content-Type",
        ]
      );

      return $response;
    }

    // There is only one session.
    if (is_object($sessions->ClientService)) {
      $results[] = $sessions->ClientService;
    }
    else {
      // Get all available sessions.
      $results = $sessions->ClientService;
    }

    $data = [];
    foreach ($results as $session) {
      $data[] = [
        'id' => $session->ID,
        'session' => $session->Name,
        'remaining' => "$session->Remaining/$session->Count",
      ];
    }

    $response = new ResourceResponse([
      'status' => TRUE,
      'message' => '',
      'timestamp' => \Drupal::time()->getRequestTime(),
      'results' => $data,
    ]);

    $response->headers->add(
      [
        'Access-Control-Allow-Origin' => 'http://0.0.0.0:8080',
        'Access-Control-Allow-Methods' => "POST, GET, OPTIONS, PATCH, DELETE",
        'Access-Control-Allow-Headers' => "Authorization, X-CSRF-Token, Content-Type",
      ]
    );
    return $response;
  }

}
