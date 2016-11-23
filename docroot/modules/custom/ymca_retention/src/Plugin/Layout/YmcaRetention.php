<?php

namespace Drupal\ymca_retention\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\layout_plugin\Plugin\Layout\LayoutBase;

/**
 * YMCA Retention layout settings.
 */
class YmcaRetention extends LayoutBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'extra_classes' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $configuration = $this->getConfiguration();
    $form['extra_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extra classes'),
      '#default_value' => $configuration['extra_classes'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $this->configuration['extra_classes'] = $form_state->getValue('extra_classes');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    $build = parent::build($regions);

    /** @var \Drupal\ymca_retention\ActivityManager $service */
    $service = \Drupal::service('ymca_retention.activity_manager');

    $settings = \Drupal::configFactory()->get('ymca_retention.instant_win');
    $build['#attached']['drupalSettings']['ymca_retention']['loss_messages'] = [
      'part_1' => $settings->get('loss_messages_long_1'),
      'part_2' => $settings->get('loss_messages_long_2'),
    ];

    $build['#attached']['drupalSettings']['ymca_retention']['resources'] = [
      'member' => Url::fromRoute('ymca_retention.member_json')->toString(),
      'member_activities' => $service->getUrl(),
      'member_chances' => Url::fromRoute('ymca_retention.member_chances_json')->toString(),
      'member_checkins' => Url::fromRoute('ymca_retention.member_checkins_json')->toString(),
      'recent_winners' => Url::fromRoute('ymca_retention.recent_winners_json')->toString(),
    ];

    return $build;
  }

}
