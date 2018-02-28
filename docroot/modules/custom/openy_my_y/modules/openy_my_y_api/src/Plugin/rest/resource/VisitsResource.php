<?php

namespace Drupal\openy_my_y_api\Plugin\rest\resource;

/**
 * Provides Visits Resource.
 *
 * @RestResource(
 *   id = "openy_my_y_api_visits",
 *   label = @Translation("Visits"),
 *   uri_paths = {
 *     "canonical" = "/openy_my_y/visits/{uid}"
 *   }
 * )
 */
class VisitsResource extends MyYResourceBase {

  /**
   * Responds to entity GET requests.
   * @return \Drupal\rest\ModifiedResourceResponse
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
      'StartDate' => date('Y-m-d', strtotime('today -2 years')),
      'UnpaidsOnly' => FALSE,
    ];

    $results = [];

    try {
      $result = $client->call(
        'ClientService',
        'GetClientVisits',
        $request,
        TRUE
      );
    } catch (\Exception $e) {
      return $this->prepareResponse([
        'status' => FALSE,
        'message' => $e->getMessage(),
      ]);
    }

    $visits = $result->GetClientVisitsResult->Visits;

    // In case if there is no appointments at all.
    if (!isset($visits->Visit)) {
      return $this->prepareResponse([
        'status' => TRUE,
      ]);
    }

    // There is only one appointment.
    if (is_object($visits->Visit)) {
      $results[] = $visits->Visit;
    }
    else {
      // Get all available results.
      $results = $visits->Visit;
    }

    $data = [];
    foreach ($results as $appointment) {
      $data[] = [
        'id' => $appointment->AppointmentID,
        'start' => $appointment->StartDateTime,
        'end' => $appointment->EndDateTime,
        'session' => $appointment->Name,
        'trainer' => [
          'id' => $appointment->Staff->ID,
          'name' => $appointment->Staff->Name,
        ],
        'location' => [
          'id' => $appointment->Location->ID,
          'name' => $appointment->Location->Name,
        ],
      ];
    }

    return $this->prepareResponse([
      'status' => TRUE,
      'results' => $data,
    ]);
  }

}
