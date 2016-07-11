<?php

namespace Drupal\ymca_retention;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\taxonomy\TermStorage;
use Drupal\ymca_mappings\Entity\Mapping;
use Drupal\ymca_retention\Entity\Member;

/**
 * Defines a leaderboard manager service.
 */
class LeaderboardManager implements LeaderboardManagerInterface {

  /**
   * Injected cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface;
   */
  protected $cache;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The injected cache backend for caching data.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getLeaderboard($branch_id = 0) {
    // Try first to load from cache.
    if ($cache = $this->cache->get('leaderboard:' . $branch_id)) {
      $leaderboard = $cache->data;

      return $leaderboard;
    }

    // Prepare taxonomy data.
    /** @var TermStorage $term_storage */
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $parents = $term_storage->loadTree('ymca_retention_activities', 0, 1);
    foreach ($parents as $parent) {
      $parent->children_ids = [];
      $children = $term_storage->loadTree('ymca_retention_activities', $parent->tid, 1);
      foreach ($children as $child) {
        $parent->children_ids[] = $child->tid;
      }
    }

    $member_ids = \Drupal::entityQuery('ymca_retention_member')
      ->condition('branch', $branch_id)
      ->execute();
    $members = \Drupal::entityTypeManager()
      ->getStorage('ymca_retention_member')
      ->loadMultiple($member_ids);

    $leaderboard = [];
    /** @var Member $member */
    foreach ($members as $rank => $member) {
      $activities = [];
      foreach ($parents as $parent) {
        $activities_ids = \Drupal::entityQuery('ymca_retention_member_activity')
          ->condition('member', $member->id())
          ->condition('activity_type', $parent->children_ids, 'IN')
          ->execute();
        $activities[] = count($activities_ids);
      }

      $leaderboard[] = [
        'rank' => $rank,
        'first_name' => $member->getFirstName(),
        'last_name' => substr($member->getLastName(), 0, 1),
        'membership_id' => substr($member->getMemberId(), -4),
        'activities' => $activities,
        'visits' => (int) $member->getVisits(),
      ];
    }

    $this->cache->set('leaderboard:' . $branch_id, $leaderboard, REQUEST_TIME + 6 * 60 * 60);
    return $leaderboard;
  }

  /**
   * {@inheritdoc}
   */
  public function getLocations() {
    // Find out unique branch ids among all the members.
    $branches = \Drupal::entityQueryAggregate('ymca_retention_member')
      ->groupBy('branch')
      ->aggregate('id', 'COUNT')
      ->execute();
    $branch_ids = [];
    foreach ($branches as $branch) {
      $branch_ids[] = $branch['branch'];
    }

    // Find location names for found branch ids.
    $location_ids = \Drupal::entityQuery('mapping')
      ->condition('type', 'location')
      ->condition('field_groupex_id', $branch_ids, 'IN')
      ->execute();

    $locations = \Drupal::entityTypeManager()
      ->getStorage('mapping')
      ->loadMultiple($location_ids);

    $locations_list = [
      [
        'branch_id' => 0,
        'name' => t('Select location...'),
      ],
    ];
    /** @var Mapping $location */
    foreach ($locations as $location) {
      $locations_list[] = [
        'branch_id' => $location->get('field_groupex_id')->value,
        'name' => $location->getName(),
      ];
    }

    return $locations_list;
  }

}
