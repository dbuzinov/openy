<?php

namespace Drupal\openy_repeat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;

/**
 * {@inheritdoc}
 */
class RepeatController extends ControllerBase {

  /**
   * Cache default.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entity_type_manager;

  /**
   * Creates a new RepeatController.
   *
   * @param CacheBackendInterface $cache
   *   Cache default.
   * @param Connection $database
   *   The Database connection.
   * @param EntityTypeManager $entity_type_manager
   *   The EntityTypeManager.
   */
  public function __construct(CacheBackendInterface $cache, Connection $database, QueryFactory $entity_query, EntityTypeManager $entity_type_manager) {
    $this->cache = $cache;
    $this->database = $database;
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default'),
      $container->get('database'),
      $container->get('entity.query'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxScheduler( Request $request, $location, $date, $category) {
    if (empty($date)) {
      $date = date('F j, l 00:00:00');
    }
    $date = strtotime($date);

    $year = date('Y', $date);
    $month = date('m', $date);
    $day = date('d', $date);
    $week = date('W', $date);
    $weekday = date('N', $date);

    $timestamp_start = $date;
    $timestamp_end = $date + 24 * 60 * 60 * 60; // Next day.

    $sql = "SELECT DISTINCT
              n.nid,
              re.id,
              nd.title as location,
              nds.title as name,
              re.class,
              CAST(re.duration / 60 AS CHAR(1)) as duration_hours,
              CAST(re.duration % 60 AS CHAR(2)) as duration_minutes,
              re.room,
              re.instructor as instructor,
              re.category,
              re.register_url as register_url,
              re.register_text as register_text,
              TRIM(LEADING '0' FROM (DATE_FORMAT(FROM_UNIXTIME(re.start), '%h:%i'))) as time_start,
              TRIM(LEADING '0' FROM (DATE_FORMAT(FROM_UNIXTIME(re.start + re.duration * 60), '%h:%i%p'))) as time_end,
              DATE_FORMAT(FROM_UNIXTIME(re.start), '%Y-%m-%d %T') as time_start_calendar,
              DATE_FORMAT(FROM_UNIXTIME(re.start + re.duration * 60), '%Y-%m-%d %T') as time_end_calendar
            FROM {node} n
            RIGHT JOIN {repeat_event} re ON re.session = n.nid
            INNER JOIN node_field_data nd ON re.location = nd.nid
            INNER JOIN node_field_data nds ON n.nid = nds.nid
            WHERE
              n.type = 'session'
              AND
              (
                (re.year = :year OR re.year = '*')
                AND
                (re.month = :month OR re.month = '*')
                AND
                (re.day = :day OR re.day = '*')
                AND
                (re.week = :week OR re.week = '*')
                AND
                (re.weekday = :weekday OR re.weekday = '*')
                AND
                (re.start <= :timestamp_end)
                AND
                (re.end >= :timestamp_start)
              )";

    $values = [];
    if (!empty($category)) {
      $sql .= "AND re.category IN ( :categories[] )";
      $values[':categories[]'] = explode(',', $category);
    }
    if (!empty($location)) {
      $sql .= "AND nd.title IN ( :locations[] )";
      $values[':locations[]'] = explode(',', $location);
    }
    $exclusions = $request->get('excl');
    if (!empty($exclusions)) {
      $sql .= "AND re.category NOT IN ( :exclusions[] )";
      $values[':exclusions[]'] = explode(',', $exclusions);
    }
    $limit = $request->get('limit');
    if (!empty($limit)) {
      $sql .= "AND re.category IN ( :limit[] )";
      $values[':limit[]'] = explode(',', $limit);
    }

    $sql .= " ORDER BY re.start";

    $values[':year'] = $year;
    $values[':month'] = $month;
    $values[':day'] = $day;
    $values[':week'] = $week;
    $values[':weekday'] = $weekday;
    $values[':timestamp_start'] = $timestamp_start;
    $values[':timestamp_end'] = $timestamp_end;

    $query = $this->database->query($sql, $values);
    $result = $query->fetchAll();

    $locations_info = $this->getLocationsInfo();
    $classes_info = $this->getClassesInfo();
    foreach ($result as $key => $item) {
      $result[$key]->location_info = $locations_info[$item->location];
      $result[$key]->class_info = $classes_info[$item->class];
    }

    return new JsonResponse($result);
  }

  /**
   * Return Location from "Session" node type.
   *
   * @return array
   */
  public function getLocations() {
    $sql = "SELECT DISTINCT nd.title as location
            FROM {node} n
            INNER JOIN node__field_session_location l ON n.nid = l.entity_id AND l.bundle = 'session'
            INNER JOIN node_field_data nd ON l.field_session_location_target_id = nd.nid
            WHERE n.type = 'session'";

    $query = $this->database->query($sql);

    return $query->fetchCol();
  }

  /**
   * Get detailed info about Location (aka branch).
   */
  public function getLocationsInfo() {
    $data = [];
    $tags = ['node_list'];
    $cid = 'openy_repeat:locations_info';
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids = $this->entityQuery
        ->get('node')
        ->condition('type','branch')
        ->execute();
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunk) {
        $branches = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
        if (!empty($branches)) {
          foreach ($branches as $node) {
            $days = $node->get('field_branch_hours')->getValue();
            $address = $node->get('field_location_address')->getValue();
            if (!empty($address[0])) {
              $address = array_filter($address[0]);
              $address = implode(', ', $address);
            }
            $data[$node->title->value] = [
              'nid' => $node->nid->value,
              'title' => $node->title->value,
              'email' => $node->field_location_email->value,
              'phone' => $node->field_location_phone->value,
              'address' => $address,
              'days' => !empty($days[0]) ? $this->getFormattedHours($days[0]) : [],
            ];
            $tags[] = 'node:' . $node->nid->value;
          }
        }
      }
      $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, $tags);
    }

    return $data;
  }

  /**
   * Get detailed info about Class.
   */
  public function getClassesInfo() {
    $data = [];
    $tags = ['node_list'];
    $cid = 'openy_repeat:classes_info';
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $nids = $this->entityQuery
        ->get('node')
        ->condition('type','class')
        ->execute();
      $nids_chunked = array_chunk($nids, 20, TRUE);
      foreach ($nids_chunked as $chunk) {
        $classes = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
        if (!empty($classes)) {
          foreach ($classes as $node) {
            $data[$node->nid->value] = [
              'nid' => $node->nid->value,
              'title' => $node->title->value,
              'description' => html_entity_decode(strip_tags(text_summary($node->field_class_description->value, $node->field_class_description->format, 600))),
            ];
            $tags[] = 'node:' . $node->nid->value;
          }
        }
      }
      $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, $tags);
    }

    return $data;
  }


  public function getFormattedHours($data) {
    $lazy_hours = $groups = $rows = [];
    foreach ($data as $day => $value) {
      // Do not process label. Store it name for later usage.
      if ($day == 'hours_label') {
        continue;
      }

      $day = str_replace('hours_', '', $day);
      $value = $value ? $value : 'closed';
      $lazy_hours[$day] = $value;
      if ($groups && end($groups)['value'] == $value) {
        $array_keys = array_keys($groups);
        $group = &$groups[end($array_keys)];
        $group['days'][] = $day;
      }
      else {
        $groups[] = [
          'value' => $value,
          'days' => [$day],
        ];
      }
    }

    foreach ($groups as $group_item) {
      $title = sprintf('%s - %s', ucfirst(reset($group_item['days'])), ucfirst(end($group_item['days'])));
      if (count($group_item['days']) == 1) {
        $title = ucfirst(reset($group_item['days']));
      }
      $hours = $group_item['value'];
      $rows[] = [$title . ':', $hours];
    }

    return $rows;
  }

}
