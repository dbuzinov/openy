<?php

namespace Drupal\ymca_retention\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Provides a block with form for tracking activity.
 *
 * @Block(
 *   id = "retention_track_activity_block",
 *   admin_label = @Translation("YMCA retention track activity block"),
 *   category = @Translation("YMCA Blocks")
 * )
 */
class TrackActivityForm extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    /** @var \Drupal\ymca_retention\ActivityManager $service */
    $service = \Drupal::service('ymca_retention.activity_manager');
    $dates = $service->getDates();
    $activity_groups = $service->getActivityGroups();

    return [
      '#theme' => 'ymca_retention_track_activity',
      '#attached' => [
        'library' => [
          'ymca_retention/activity',
        ],
        'drupalSettings' => [
          'ymca_retention' => [
            'activity' => [
              'dates' => $dates,
              'activity_groups' => $activity_groups,
              'member_activities' => Url::fromRoute('ymca_retention.member_activities_json')->toString(),
            ],
          ],
        ],
      ],
    ];
  }

}
