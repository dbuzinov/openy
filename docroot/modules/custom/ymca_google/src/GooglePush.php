<?php

namespace Drupal\ymca_google;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ymca_groupex_google_cache\Entity\GroupexGoogleCache;
use Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface;

/**
 * Class GooglePush.
 *
 * @package Drupal\ymca_google
 */
class GooglePush {

  /**
   * Date format for RRULE.
   */
  const RRULE_DATE = 'Ymd\THis\Z';

  /**
   * Test calendar name.
   */
  const TEST_CALENDAR_NAME = 'TESTING';

  /**
   * Wrapper to be used.
   *
   * @var GcalGroupexWrapperInterface
   */
  protected $dataWrapper;

  /**
   * Config Factory.
   *
   * @var ConfigFactory
   */
  protected $configFactory;

  /**
   * Google Calendar Service.
   *
   * @var \Google_Service_Calendar
   */
  protected $calService;

  /**
   * Google Calendar Events Service.
   *
   * @var \Google_Service_Calendar_Events_Resource
   */
  protected $calEvents;

  /**
   * Google Client.
   *
   * @var \Google_Client
   */
  protected $googleClient;

  /**
   * The logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  /**
   * Entity type manager.
   *
   * @var EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Proxy.
   *
   * @var DrupalProxy
   */
  protected $proxy;

  /**
   * Google calendars list.
   *
   * @var array
   */
  protected $calendars = [];

  /**
   * Query factory.
   *
   * @var QueryFactory
   */
  protected $query;

  /**
   * Production flag.
   *
   * @var bool
   */
  protected $isProduction;

  /**
   * The entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $cacheStorage;

  /**
   * GooglePush constructor.
   *
   * @param GcalGroupexWrapperInterface $data_wrapper
   *   Data wrapper.
   * @param ConfigFactory $config_factory
   *   Config Factory.
   * @param LoggerChannelInterface $logger
   *   The logger channel.
   * @param EntityTypeManager $entity_type_manager
   *   Entity type manager.
   * @param DrupalProxy $proxy
   *   The Proxy.
   * @param QueryFactory $query
   *   Query factory.
   */
  public function __construct(GcalGroupexWrapperInterface $data_wrapper, ConfigFactory $config_factory, LoggerChannelInterface $logger, EntityTypeManager $entity_type_manager, DrupalProxy $proxy, QueryFactory $query) {
    $this->dataWrapper = $data_wrapper;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->proxy = $proxy;
    $this->query = $query;

    $this->isProduction = $this->configFactory->get('ymca_google.settings')->get('is_production');
    $this->cacheStorage = $this->entityTypeManager->getStorage(GcalGroupexWrapper::ENTITY_TYPE);

    // Get the API client and construct the service object.
    $this->googleClient = $this->getClient();
    $this->calService = new \Google_Service_Calendar($this->googleClient);
    $this->calEvents = $this->calService->events;
  }

  /**
   * Proceed all events collected by add methods.
   */
  public function proceed() {
    $api_version = $this->dataWrapper->settings->get('api_version');
    switch ($api_version) {
      case 1:
        $this->proceedLegacy();
        break;

      case 2:
        $this->proceedCurrent();
        break;

    }
  }

  /**
   * Legacy version of proceed().
   */
  protected function proceedLegacy() {
    $data = $this->dataWrapper->getProxyData();

    foreach ($data as $op => $entities) {
      Timer::start($op);
      $processed[$op] = 0;

      /** @var GroupexGoogleCache $entity */
      foreach ($entities as $entity) {

        // Refresh the token if it's expired.
        if ($this->googleClient->isAccessTokenExpired()) {
          $this->logger->info('Token is expired. Refreshing...');

          $this->googleClient->refreshToken($this->googleClient->getRefreshToken());
          $editable = $this->configFactory->getEditable('ymca_google.token');
          $editable->set('credentials', json_decode($this->googleClient->getAccessToken(), TRUE));
          $editable->save();
        }

        $gcal_id = $this->getCalendarIdByName($entity->field_gg_location->value);

        if (!$gcal_id) {
          // Failed to get calendar ID. Continue with next event.
          continue;
        }

        switch ($op) {
          case 'update':
            // Do not update entities in testing mode.
            if (!$this->isProduction) {
              break;
            }

            $event = $this->drupalEntityToGcalEvent($entity);
            if (!$event) {
              break;
            }

            try {
              $updated = $this->calEvents->update(
                $gcal_id,
                $entity->field_gg_gcal_id->value,
                $event
              );

              $processed[$op]++;

              // Saving updated entity only when it was pushed successfully.
              $entity->set('field_gg_google_event', serialize($updated));
              $entity->save();
            }
            catch (\Google_Service_Exception $e) {
              if ($e->getCode() == 403) {
                $message = 'Google_Service_Exception [%op]: %message';
                $this->logger->error(
                  $message,
                  [
                    '%message' => $e->getMessage(),
                    '%op' => $op,
                  ]
                );
                $this->logStats($op, $processed);
                if (strstr($e->getMessage(), 'Rate Limit Exceeded')) {
                  // Rate limit exceeded, retry. @todo limit number of retries.
                  return;
                }
              }
              else {
                $message = 'Google Service Exception for operation %op for Entity: %uri : %message';
                $this->logger->error(
                  $message,
                  [
                    '%op' => $op,
                    '%uri' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
                    '%message' => $e->getMessage(),
                  ]
                );
                $this->logStats($op, $processed);
              }

            }
            catch (\Exception $e) {
              $msg = '%type : Error while updating event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%type' => get_class($e),
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;

          case 'delete':
            // Do not delete entities in testing mode.
            if (!$this->isProduction) {
              break;
            }
            try {
              $this->calEvents->delete(
                $gcal_id,
                $entity->field_gg_gcal_id->value
              );

              $storage = $this->entityTypeManager->getStorage('groupex_google_cache');
              $storage->delete([$entity]);

              $processed[$op]++;
            }
            catch (\Google_Service_Exception $e) {
              if ($e->getCode() == 403) {
                $message = 'Google_Service_Exception [%op]: %message';
                $this->logger->error(
                  $message,
                  [
                    '%message' => $e->getMessage(),
                    '%op' => $op,
                  ]
                );
                $this->logStats($op, $processed);
                if (strstr($e->getMessage(), 'Rate Limit Exceeded')) {
                  // Rate limit exceeded, retry. @todo limit number of retries.
                  return;
                }
              }
              else {
                $message = 'Google Service Exception for operation %op for Entity: %uri : %message';
                $this->logger->error(
                  $message,
                  [
                    '%op' => $op,
                    '%uri' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
                    '%message' => $e->getMessage(),
                  ]
                );
                $this->logStats($op, $processed);
              }

            }
            catch (\Exception $e) {
              $msg = 'Error while deleting event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;

          case 'insert':
            $event = $this->drupalEntityToGcalEvent($entity);
            if (!$event) {
              break;
            }

            try {
              $event = $this->calEvents->insert($gcal_id, $event);

              $entity->set('field_gg_gcal_id', $event->getId());
              $entity->set('field_gg_google_event', serialize($event));
              $entity->save();

              $processed[$op]++;
            }
            catch (\Google_Service_Exception $e) {
              if ($e->getCode() == 403) {
                $message = 'Google_Service_Exception [%op]: %message';
                $this->logger->error(
                  $message,
                  [
                    '%message' => $e->getMessage(),
                    '%op' => $op,
                  ]
                );
                $this->logStats($op, $processed);
                if (strstr($e->getMessage(), 'Rate Limit Exceeded')) {
                  // Rate limit exceeded, retry. @todo limit number of retries.
                  return;
                }
              }
              else {
                $message = 'Google Service Exception for operation %op for Entity: %uri : %message';
                $this->logger->error(
                  $message,
                  [
                    '%op' => $op,
                    '%uri' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
                    '%message' => $e->getMessage(),
                  ]
                );
                $this->logStats($op, $processed);
              }

            }
            catch (\Exception $e) {
              $msg = 'Error while inserting event for entity [%id]: %msg';
              $this->logger->error($msg, [
                '%id' => $entity->id(),
                '%msg' => $e->getMessage(),
              ]);
            }

            break;
        }

      }

      $this->logStats($op, $processed);

    }

    // Mark this step as done in the schedule.
    $this->dataWrapper->next();

  }

  /**
   * Current version of proceed().
   */
  protected function proceedCurrent() {
    $data = $this->dataWrapper->getProxyData();

    // Insert.
    $op = 'insert';
    Timer::start($op);
    $processed[$op] = 0;

    if (!empty($data[$op])) {
      foreach ($data[$op] as $entity) {
        try {
          $this->pushNewEvent($entity);
          $processed[$op]++;
        }
        catch (\Google_Service_Exception $e) {
          if ($e->getCode() == 403) {
            $message = 'Google_Service_Exception [%op]: %message';
            $this->logger->error(
              $message,
              [
                '%message' => $e->getMessage(),
                '%op' => $op,
              ]
            );
            if (strstr($e->getMessage(), 'Rate Limit Exceeded')) {
              // Rate limit exceeded, retry.
              // @todo Limit number of retries.
              return;
            }
          }
          else {
            $message = 'Google Service Exception for operation %op for Entity: %uri : %message';
            $this->logger->error(
              $message,
              [
                '%op' => $op,
                '%uri' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
                '%message' => $e->getMessage(),
              ]
            );
          }
        }
        catch (\Exception $e) {
          $msg = 'Failed to push event for cache entity ID %id. Message: %msg';
          $this->logger->error(
            $msg,
            [
              '%id' => $entity->id(),
              '%msg' => $e->getMessage(),
            ]
          );
        }
      }
    }

    // Log insert.
    $this->logStats($op, $processed);

    // Update.
    $op = 'update';
    Timer::start($op);
    $processed[$op] = 0;
    if (!empty($data[$op])) {
      foreach ($data[$op] as $entity) {
        try {
          $this->pushUpdatedEvent($entity);
          $processed[$op]++;
        }
        catch (\Google_Service_Exception $e) {
          if ($e->getCode() == 403) {
            $message = 'Google_Service_Exception [%op]: %message';
            $this->logger->error(
              $message,
              [
                '%message' => $e->getMessage(),
                '%op' => $op,
              ]
            );
            if (strstr($e->getMessage(), 'Rate Limit Exceeded')) {
              // Rate limit exceeded, retry.
              // @todo Limit number of retries.
              return;
            }
          }
          else {
            $message = 'Google Service Exception for operation %op for Entity: %uri : %message';
            $this->logger->error(
              $message,
              [
                '%op' => $op,
                '%uri' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
                '%message' => $e->getMessage(),
              ]
            );
          }
        }
        catch (\Exception $e) {
          $msg = 'Failed to update event for cache entity ID %id. Message: %msg';
          $this->logger->error(
            $msg,
            [
              '%id' => $entity->id(),
              '%msg' => $e->getMessage(),
            ]
          );
        }
      }
    }
    $this->logStats($op, $processed);

    // Delete.
    $op = 'delete';
    Timer::start($op);
    $processed[$op] = 0;

    if (!empty($data[$op])) {
      foreach ($data[$op] as $item) {
        try {
          $this->deleteEvent($item);
          $processed[$op]++;
        }
        catch (\Exception $e) {
          $msg = 'Failed to delete event for cache entity ID %id. Message: %msg';
          $this->logger->error(
            $msg,
            [
              '%id' => $entity->id(),
              '%msg' => $e->getMessage(),
            ]
          );
        }
      }
    }
    $this->logStats($op, $processed);

    // Mark this step as done in the schedule.
    $this->dataWrapper->next();
  }

  /**
   * Checks whether the entity has been pushed as Gcal Event.
   *
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Entity.
   *
   * @return string|bool
   *   FALSE or Gcal Event ID.
   */
  private function isPushed(GroupexGoogleCacheInterface $entity) {
    $field = $entity->get('field_gg_gcal_id');
    if (!$field->isEmpty()) {
      $value = $field->getValue();
      if (isset($value[0])) {
        return $value[0]['value'];
      }
    }

    return FALSE;
  }

  /**
   * Update GCal event (instances).
   *
   * @param \Drupal\ymca_groupex_google_cache\Entity\GroupexGoogleCache $entity
   *   Parent cache entity.
   *
   * @throws \Exception
   */
  public function pushUpdatedEvent(GroupexGoogleCache $entity) {
    $children = $this->proxy->findChildrenNotPushed($entity);
    foreach ($children as $child_id) {
      $child_entity = $this->cacheStorage->load($child_id);
      if (!$cal_id = $this->getCalIdByCacheEntity($child_entity)) {
        throw new \Exception('Failed to get Google Calendar ID.');
      }

      try {
        $instance = $this->getEventInstance($child_entity);
      }
      catch (\Exception $e) {
        $message = sprintf('%s Child Entity ID: %s.', $e->getMessage(), $child_entity->id());
        throw new \Exception($message);
      }

      $this->populateGenericEventData($instance, $child_entity);

      $updated = $this->calService->events->update($cal_id, $instance->getId(), $instance);

      // Save Google response.
      $child_entity->set('field_gg_gcal_id', $updated->getId());
      $child_entity->set('field_gg_google_event', serialize($updated));

      // Set UTC start timestamp.
      $tsDateTime = $this->proxy->extractEventDateTime($child_entity, 'start', 'UTC');
      $child_entity->set('field_gg_ts_utc', $tsDateTime->getTimestamp());
      $child_entity->save();

      $msg = 'Instance with ID %gcal_id was updated from child item with ID %child_id.';
      $this->logger->info(
        $msg,
        [
          '%gcal_id' => $updated->getId(),
          '%child_id' => $child_entity->id(),
        ]
      );
    }
  }

  /**
   * Delete an event from Google calendar.
   *
   * There 2 cases for deleting the events from Google calendar.
   *  - Deleting an event with it's children.
   *    If we've got parent entity we should delete event with all it's
   *    instances (including corresponding cache items in DB).
   *
   *  - Deleting only single instance of the event.
   *    If we've got only an instance we should delete this instance only.
   *
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Cache entity.
   *
   * @return bool
   *   TRUE if deleting was OK.
   *
   * @throws \Exception
   */
  public function deleteEvent(GroupexGoogleCacheInterface $entity) {
    // If an entity doesn't have parent ref it's a parent entity.
    $parent = FALSE;
    $ref = $entity->get('field_gg_parent_ref');
    if ($ref->isEmpty()) {
      $parent = TRUE;
    }

    // Deal with parent entity.
    if (TRUE == $parent) {
      $entity_id = $entity->id();

      $event_field = $entity->get('field_gg_gcal_id');
      if (!$event_field->isEmpty()) {
        // If event has Gcal ID we should delete it in Google.
        $event_id = $entity->field_gg_gcal_id->value;

        $cal_id = $this->getCalendarIdByName($entity->field_gg_location->value);
        if (!$cal_id) {
          throw new \Exception('Failed to get Google Calendar ID');
        }

        try {
          $this->calService->events->delete($cal_id, $event_id);
        }
        catch (\Exception $e) {
          throw new \Exception('Failed to delete Google event. Message: ' . $e->getMessage());
        }
      }

      // Remove children.
      $children = $this->proxy->findChildren($entity_id);
      $chunks = array_chunk($children, DrupalProxy::ENTITY_LOAD_CHUNK);
      foreach ($chunks as $chunk) {
        $entities = $this->cacheStorage->loadMultiple($chunk);
        $this->cacheStorage->delete($entities);
      }

      // Remove parent entity.
      $entity->delete();

      $msg = 'The event was deleted. Cache ID: %cache_id, Gcal ID: %gcal_id';
      $this->logger->info(
        $msg,
        [
          '%cache_id' => $entity_id,
          '%gcal_id' => $event_id,
        ]
      );

    }

    // @todo Implement instance deleting.

    return TRUE;
  }

  /**
   * Get Calendar ID by cache entity.
   *
   * @param \Drupal\ymca_groupex_google_cache\Entity\GroupexGoogleCache $entity
   *   Cache item.
   *
   * @return string|bool
   *   Calendar ID.
   */
  public function getCalIdByCacheEntity(GroupexGoogleCache $entity) {
    return $this->getCalendarIdByName($entity->field_gg_location->value);
  }

  /**
   * Delete all events in the calendar.
   *
   * @param string $id
   *   Calendar ID.
   *
   * @throws \Exception
   */
  public function clearCalendar($id) {
    // Do not include feature events. For efficiency.
    $date_time = new \DateTime('now');
    $interval = new \DateInterval('P1Y');
    $date_time->add($interval);
    $defaults = ['timeMax' => $date_time->format('c')];

    $events = $this->calEvents->listEvents($id, $defaults);

    while (TRUE) {
      foreach ($events->getItems() as $event) {
        try {
          $this->calEvents->delete($id, $event->getId());
        }
        catch (\Exception $e) {
          $msg = 'Failed to delete event. Message: %msg';
          $this->logger->notice($msg, ['%msg' => $e->getMessage()]);
        }
      }
      $page_token = $events->getNextPageToken();
      if ($page_token) {
        $opt_params = ['pageToken' => $page_token];
        $events = $this->calEvents->listEvents($id, $opt_params + $defaults);
      }
      else {
        break;
      }
    }
  }

  /**
   * Get Google calendar event instance by cache entity.
   *
   * @param \Drupal\ymca_groupex_google_cache\Entity\GroupexGoogleCache $entity
   *   Cache entity.
   *
   * @return \Google_Service_Calendar_Event
   *   Google Calendar event.
   *
   * @throws \Exception
   */
  public function getEventInstance(GroupexGoogleCache $entity) {
    // Get parent entity.
    if (!$parent_id = $entity->field_gg_parent_ref->target_id) {
      throw new \Exception('Parent entity reference not found.');
    }

    $parent = $this->cacheStorage->load($parent_id);

    // Try to get recurrent from parent entity or from its ics parent.
    if ($recurrence = $this->getRecurrence($parent)) {
      $recurrence_parent = $parent;
    }
    else {
      if (!$parent_ics = $this->proxy->findParentEntityByClassId($parent->field_gg_ics_par->value)) {
        throw new \Exception('Failed to find Parent ICS entity.');
      }
      $recurrence_parent = $parent_ics;
    }

    if (!$event_id = $this->isPushed($recurrence_parent)) {
      throw new \Exception('Event is still not pushed.');
    }

    if (!$cal_id = $this->getCalIdByCacheEntity($entity)) {
      throw new \Exception('Failed to get Calendar ID from parent entity.');
    }

    // Get event start DateTime.
    if (!$startDateTime = $this->proxy->extractEventDateTime($entity, 'start', GcalGroupexWrapper::TIMEZONE)) {
      throw new \Exception('Failed to get start DateTime for the event');
    }

    $interval = new \DateInterval('P6D');

    $timeMin = clone $startDateTime;
    $timeMin->sub($interval);

    $timeMax = clone $startDateTime;
    $timeMax->add($interval);

    $opts = [
      'timeMin' => $timeMin->format('c'),
      'timeMax' => $timeMax->format('c'),
    ];

    $events = $this->calService->events->instances($cal_id, $event_id, $opts);

    if ($events->count() > 1) {
      throw new \Exception('Found more than one instance for the child.');
    }

    if (!$events->count()) {
      throw new \Exception('Instance not found.');
    }

    $items = $events->getItems();
    return $items[0];
  }

  /**
   * Gets recurrence from entity.
   *
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Entity.
   *
   * @return string|bool
   *   FALSE means no recurrence.
   *
   * @throws \Exception
   */
  private function getRecurrence(GroupexGoogleCacheInterface $entity) {
    // Available types: weekly, biweekly, NULL, none.
    $recurrence_field = $entity->field_gg_ics_rec->value ?: NULL;
    switch ($recurrence_field) {
      case 'weekly';
        return 'RRULE:FREQ=WEEKLY';

      case 'biweekly':
        return 'RRULE:FREQ=WEEKLY;INTERVAL=2';

      case 'none';
        return FALSE;

      case NULL:
        return FALSE;

      default:
        $msg = sprintf('Invalid recurrence value detected. Entity ID: %s', $entity->id());
        throw new \Exception($msg);
    }
  }

  /**
   * Pushes new google event.
   *
   * Make sure you use this function only for creating new events.
   *
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Parent cache entity.
   *
   * @throws \Exception
   */
  public function pushNewEvent(GroupexGoogleCacheInterface $entity) {
    // Check whether the event was pushed.
    if ($entity->field_gg_google_event->value || $entity->field_gg_gcal_id->value) {
      throw new \Exception('The event has been already pushed.');
    }

    $children = $this->proxy->findChildren($entity->id());
    if (empty($children)) {
      $msg = 'Skip pushing event. No children found. ID: %id.';
      $this->logger->notice(
        $msg,
        [
          '%id' => $entity->id(),
        ]
      );
      return;
    }

    $event = new \Google_Service_Calendar_Event();

    // Find the most weighted child to use it's data.
    $weighted = $this->cacheStorage->load($children[0]);

    if (!$gcal_id = $this->getCalendarIdByName($weighted->field_gg_location->value)) {
      throw new \Exception('Failed to get Google calendar ID.');
    }

    $this->populateGenericEventData($event, $weighted);

    // Set recurrence if available.
    if ($recurrence = $this->getRecurrence($entity)) {
      $event->setRecurrence([$recurrence]);
    }

    $created = $this->calEvents->insert($gcal_id, $event);

    // Save Google response.
    $entity->set('field_gg_gcal_id', $created->getId());
    $entity->set('field_gg_google_event', serialize($created));

    // Remove update flag.
    $entity->set('field_gg_need_up', 0);

    $entity->save();

    $msg = 'Gcal event %gcal_id created from parent entity %parent_id.';
    $this->logger->info(
      $msg,
      [
        '%gcal_id' => $created->getId(),
        '%parent_id' => $entity->id(),
      ]
    );
  }

  /**
   * Populates generic event data for Google event.
   *
   * The next fields will be populated:
   *   - Summary.
   *   - Location.
   *   - Description.
   *   - Start time.
   *   - End time.
   *
   * @param \Google_Service_Calendar_Event $event
   *   Google calendar event.
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Cache entity.
   *
   * @throws \Exception
   */
  public function populateGenericEventData(\Google_Service_Calendar_Event &$event, GroupexGoogleCacheInterface $entity) {
    $event->setSummary(trim($entity->field_gg_title->value));
    $event->setLocation(trim($entity->field_gg_location->value));
    $event->setDescription($this->getDescription($entity));

    // Set start time.
    if (!$startDateTime = $this->proxy->extractEventDateTime($entity, 'start', GcalGroupexWrapper::TIMEZONE)) {
      throw new \Exception('Failed to extract start time from cache entity');
    }
    $start = new \Google_Service_Calendar_EventDateTime();
    $start->setDateTime($startDateTime->format(DATETIME_DATETIME_STORAGE_FORMAT));
    $start->setTimeZone(GcalGroupexWrapper::TIMEZONE);
    $event->setStart($start);

    // Set end time.
    if (!$endDateTime = $this->proxy->extractEventDateTime($entity, 'end', GcalGroupexWrapper::TIMEZONE)) {
      throw new \Exception('Failed to extract end time from Cache Entity with ID');
    }
    $end = new \Google_Service_Calendar_EventDateTime();
    $end->setDateTime($endDateTime->format(DATETIME_DATETIME_STORAGE_FORMAT));
    $end->setTimeZone(GcalGroupexWrapper::TIMEZONE);
    $event->setEnd($end);
  }

  /**
   * Prepares description for the Google vvent.
   *
   * @param \Drupal\ymca_groupex_google_cache\GroupexGoogleCacheInterface $entity
   *   Cache entity to get basic description.
   *
   * @return string
   *   Description.
   */
  protected function getDescription(GroupexGoogleCacheInterface $entity) {
    // We should prepend description with instructor.
    $instructor = trim($entity->field_gg_instructor->value);

    // Check whether we have subbed one.
    $regex = '/<span class=\"subbed\".*><br>(.*)<\/span>/';
    preg_match($regex, $instructor, $match);
    if (isset($match[1])) {
      $instructor = str_replace($match[0], ' ', $instructor);
      $instructor .= $match[1];
    }

    // Add instructor to description.
    $description = 'Instructor: ' . $instructor . "\n\n";

    // Remove garbage.
    $description .= strip_tags(trim(html_entity_decode($entity->field_gg_description->value)));

    return $description;
  }

  /**
   * Get Gcal ID by it's name (summary).
   *
   * @param string $name
   *   Calendar name (summary).
   *
   * @return bool|mixed
   *   Calendar ID.
   */
  public function getCalendarIdByName($name) {
    if (!$this->isProduction) {
      $name = self::TEST_CALENDAR_NAME;
    }

    // Return ID from cache if exists.
    if (array_key_exists($name, $this->calendars)) {
      return $this->calendars[$name];
    }

    // There is no calendar in the cache. Let's get data form the server.
    $raw_calendars = $this->getRawCalendars();
    if ($raw_calendars === FALSE) {
      return FALSE;
    }

    foreach ($raw_calendars as $raw_calendar) {
      $this->calendars[$raw_calendar->summary] = $raw_calendar->id;
    }

    // Check the cache again.
    if (array_key_exists($name, $this->calendars)) {
      return $this->calendars[$name];
    }

    // There is no calendar on the server. Let's create it.
    if ($id = $this->createCalendar($name)) {
      $this->calendars[$name] = $id;
      return $id;
    }

    return FALSE;
  }

  /**
   * Log.
   *
   * @param string $op
   *   Operation.
   * @param array $processed
   *   Processed.
   *
   * @throws \Exception
   */
  private function logStats($op, $processed) {
    $data = $this->dataWrapper->getProxyData();
    $schedule = $this->dataWrapper->getSchedule();
    $timeZone = new \DateTimeZone('UTC');
    $current = $schedule['current'];

    $endDateTime = DrupalDateTime::createFromTimestamp($schedule['steps'][$current]['end'], $timeZone);
    $endDate = $endDateTime->format('c');

    $message = 'Stats: op - %op, items - %items, processed - %processed, success - %success%. Time - %time.';
    $this->logger->info(
      $message,
      [
        '%op' => $op,
        '%items' => count($data[$op]),
        '%time' => Timer::read($op) / 1000 . ' sec.',
        '%processed' => $processed[$op],
        '%success' => count($data[$op]) == 0 ? '100' : $processed[$op] * 100 / count($data[$op]),
      ]
    );

    Timer::stop($op);
  }

  /**
   * Convert cached entity to an event.
   *
   * @param GroupexGoogleCache $entity
   *   Entity.
   *
   * @return \Google_Service_Calendar_Event|bool
   *   Event.
   */
  private function drupalEntityToGcalEvent(GroupexGoogleCache $entity) {
    $groupex_id = $entity->field_gg_class_id->value;

    $field_date = $entity->get('field_gg_date');
    $list_date = $field_date->getValue();

    $description = '';
    $instructor = '';
    $default = trim($entity->field_gg_instructor->value);
    if (empty($default)) {
      $sub_instructor = trim($entity->field_gg_sub_instructor->value);
      if (empty($sub_instructor)) {
        $original_instructor = trim($entity->field_gg_orig_instructor->value);
        if (!empty($original_instructor)) {
          $instructor = $original_instructor;
        }
      }
      else {
        $instructor = $sub_instructor;
      }
    }
    else {
      $instructor = $default;
    }

    // Check if instructor value contains subbed HTML and strip tags.
    $regex = '/<span class=\"subbed\".*><br>(.*)<\/span>/';
    preg_match($regex, $instructor, $match);
    if (isset($match[1])) {
      $instructor = str_replace($match[0], ' ', $instructor);
      $instructor .= $match[1];
    }

    if (!empty($instructor)) {
      $description = 'Instructor: ' . $instructor . "\n\n";
    }

    $description .= strip_tags(trim(html_entity_decode($entity->field_gg_description->value)));
    $location = trim($entity->field_gg_location->value);
    $summary = trim($entity->field_gg_title->value);

    // Prepare objects.
    $timezone = new \DateTimeZone('UTC');
    $date_time = new \DateTime();
    $date_time->setTimezone($timezone);

    // Start of the event.
    $start_date_time = clone $date_time;
    $start_date_time->setTimestamp($entity->field_gg_timestamp_start->value);

    // End of the event.
    $end_date_time = clone $date_time;
    $end_date_time->setTimestamp($entity->field_gg_timestamp_end->value);

    // Create Google event.
    $event = new \Google_Service_Calendar_Event([
      'summary' => $summary,
      'location' => $location,
      'description' => $description,
      'start' => [
        'dateTime' => $start_date_time->format(DATETIME_DATETIME_STORAGE_FORMAT),
        'timeZone' => 'UTC',
      ],
      'end' => [
        'dateTime' => $end_date_time->format(DATETIME_DATETIME_STORAGE_FORMAT),
        'timeZone' => 'UTC',
      ],
    ]);

    // Add logic for recurring events.
    if (count($list_date) > 1) {
      $rrule = [];

      // Get start timestamps of all events and sort them.
      $timestamps = [];
      foreach ($list_date as $id => $item) {
        $stamps = $this->proxy->buildTimestamps($item['value'], $entity->field_gg_time->value);
        $timestamps[$id] = $stamps['start'];
      }
      sort($timestamps, SORT_NUMERIC);

      // Check the frequency between dates. Exit if it's not weekly event.
      $diff = ($timestamps[1] - $timestamps[0]) / 604800;
      if (!is_int($diff)) {
        $this->logger->error('Got invalid interval %int for frequency for Groupex event %id', ['%int' => $diff, ['%id' => $groupex_id]]);
        return FALSE;
      }

      // Get datetime object of the last event (RRULE until).
      $until_date_time = clone $date_time;
      $until_date_time->setTimestamp(end($timestamps));
      $until_str = $until_date_time->format(self::RRULE_DATE);

      $rrule[] = "RRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=$until_str";

      /* Events may have excluded dates. In order to check whether the date
       * was excluded we need to check every event in the list of date field
       * of the cache entity. If date is not present - it's excluded. */

      // Get list of groupex dates in simple format.
      $dates = [];
      foreach ($timestamps as $timestamp_item) {
        $dates[] = $date_time->setTimestamp($timestamp_item)->format(self::RRULE_DATE);
      }

      // Loop over the each single week and check if date exists in the event.
      $exclude = [];
      $current = $start_date_time->getTimestamp();
      while ($current <= $until_date_time->getTimestamp()) {
        $date_time->setTimestamp($current);
        $needle = $date_time->format(self::RRULE_DATE);
        if (!in_array($needle, $dates)) {
          $exclude[] = $needle;
        }

        // Go to next week.
        $date_time->add(new \DateInterval('P1W'));
        $current = $date_time->getTimestamp();
      }

      if (!empty($exclude)) {
        // We've got some excluded dates. Add them to event object.
        $rrule[] = "EXDATE:" . implode(',', $exclude);
      }

      $event['recurrence'] = $rrule;
    }

    return $event;
  }

  /**
   * Returns an authorized API client.
   *
   * @return \Google_Client
   *   The authorized client object
   *
   * @see https://developers.google.com/google-apps/calendar/quickstart/php
   */
  private function getClient() {
    $settings = $this->configFactory->get('ymca_google.settings');
    $token = $this->configFactory->get('ymca_google.token');

    $client = new \Google_Client();
    $client->setApplicationName($settings->get('application_name'));
    $client->setScopes(\Google_Service_Calendar::CALENDAR);
    $client->setAuthConfig(json_encode($settings->get('auth_config')));
    $client->setAccessToken(json_encode($token->get('credentials')));

    $client->setAccessType('offline');

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
      $client->refreshToken($client->getRefreshToken());
      $editable = $this->configFactory->getEditable('ymca_google.token');
      $editable->set('credentials', json_decode($client->getAccessToken(), TRUE));
      $editable->save();
    }

    return $client;
  }

  /**
   * Return raw list of calendars (except primary).
   *
   * @return array|bool
   *   Array of Gcal list entries.
   */
  public function getRawCalendars() {
    $data = [];

    try {
      $list = $this->calService->calendarList->listCalendarList();
      while (TRUE) {
        foreach ($list->getItems() as $calendarListEntry) {
          // Do not return primary calendar.
          if (!$calendarListEntry->primary) {
            $data[] = $calendarListEntry;
          }
        }
        $pageToken = $list->getNextPageToken();
        if ($pageToken) {
          $optParams = array('pageToken' => $pageToken);
          try {
            $list = $this->calService->calendarList->listCalendarList($optParams);
          }
          catch (\Exception $e) {
            $msg = 'Failed to get the list of calendars. Message: %msg';
            $this->logger->error($msg, ['%msg' => $e->getMessage()]);
          }
        }
        else {
          break;
        }
      }
    }
    catch (\Exception $e) {
      $msg = 'Failed to get the list of calendars. Message: %msg';
      $this->logger->error($msg, ['%msg' => $e->getMessage()]);
      return FALSE;
    }

    return $data;
  }

  /**
   * Clear primary calendar.
   *
   * Tries 3 times and then exit.
   */
  public function clearPrimaryCalendar() {
    for ($i = 0; $i <= 2; $i++) {
      try {
        $this->calService->calendars->clear('primary');
        $this->logger->info('Primary calendar was cleared.');
        break;
      }
      catch (\Exception $e) {
        $message = 'Failed to clear primary calendar. Message: %msg';
        $this->logger->error($message, ['%msg' => $e->getMessage()]);
      }
    }
  }

  /**
   * Clear all calendars (except primary).
   */
  public function deleteAllCalendars() {
    foreach ($this->getRawCalendars() as $item) {
      $this->deleteCalendar($item->id);
    }
  }

  /**
   * Remove calendar.
   *
   * Tries 3 times and then exit.
   *
   * @param string $id
   *   Calendar ID.
   */
  private function deleteCalendar($id) {
    for ($i = 0; $i <= 2; $i++) {
      try {
        $this->calService->calendars->delete($id);
        $this->logger->info('Calendar %id was deleted.', ['%id' => $id]);
        break;
      }
      catch (\Exception $e) {
        $message = 'Failed to delete the calendar %id. Message: %msg';
        $this->logger->error($message,
          [
            '%id' => $id,
            '%msg' => $e->getMessage(),
          ]
        );
      }
    }
  }

  /**
   * Create google calendar.
   *
   * @param string $name
   *   Calendar summary.
   *
   * @return mixed
   *   Calendar ID.
   */
  private function createCalendar($name) {
    $calendar = new \Google_Service_Calendar_Calendar();
    $calendar->setSummary($name);
    $calendar->setTimeZone(GcalGroupexWrapper::TIMEZONE);

    try {
      $createdCalendar = $this->calService->calendars->insert($calendar);
      $id = $createdCalendar->getId();
      $this->logger->info('Calendar was created: id: %id, name: %name', ['%id' => $id, '%name' => $name]);
      return $id;
    }
    catch (\Exception $e) {
      $msg = 'Failed to create calendar with name: %name. Error: %error';
      $this->logger->error(
        $msg,
        [
          '%name' => $name,
          '%error' => $e->getMessage(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Remove all cached entities.
   *
   * WARNING! Do not run this method unless you a aware of the consequences.
   */
  public function clearCache() {
    $ids = $this->query->get('groupex_google_cache')->execute();
    $chunks = array_chunk($ids, 10);
    $storage = $this->entityTypeManager->getStorage('groupex_google_cache');
    foreach ($chunks as $chunk) {
      $cache = GroupexGoogleCache::loadMultiple($chunk);
      $storage->delete($cache);
    }
  }

}
