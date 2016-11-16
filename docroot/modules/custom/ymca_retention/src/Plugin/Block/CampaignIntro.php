<?php

namespace Drupal\ymca_retention\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides an intro block with logo and dates of campaign.
 *
 * @Block(
 *   id = "retention_campaign_intro_block",
 *   admin_label = @Translation("[YMCA Retention] Campaign intro"),
 *   category = @Translation("YMCA Blocks")
 * )
 */
class CampaignIntro extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'slogan' => 'Participate to win',
      'show_picture' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['show_picture'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Picture'),
      '#default_value' => isset($config['show_picture']) ? $config['show_picture'] : TRUE,
      '#description' => $this->t('Display a background picture for the intro block'),
    ];

    $form['slogan'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Campaign slogan'),
      '#default_value' => isset($config['slogan']) ? $config['slogan'] : '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->setConfigurationValue('slogan', $form_state->getValue('slogan'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();

    // Get retention settings.
    $settings = \Drupal::config('ymca_retention.general_settings');

    // Get start and end date of retention campaign.
    $date_start = new \DateTime($settings->get('date_campaign_open'));
    $date_end = new \DateTime($settings->get('date_campaign_close'));

    /** @var \Drupal\Core\Datetime\DateFormatter $date_formatter */
    $date_formatter = \Drupal::service('date.formatter');

    // Prepare campaign dates.
    $dates = $date_formatter->format($date_start->getTimestamp(), 'custom', 'F j');
    $dates .= ' – ';
    if ($date_start->format('F') == $date_end->format('F')) {
      $dates .= $date_formatter->format($date_end->getTimestamp(), 'custom', 'j');
    }
    else {
      $dates .= $date_formatter->format($date_end->getTimestamp(), 'custom', 'F j');
    }

    $build = [
      '#theme' => 'ymca_retention_intro',
      '#content' => [
        'slogan' => $config['slogan'],
        'show_picture' => $config['show_picture'],
        'dates' => $dates,
      ],
      '#cache' => [
        'contexts' => [
          'url.path',
        ],
      ],
    ];
    if ($config['show_picture']) {
      $hero_path = drupal_get_path('theme', 'ymca') . '/prototypes/yfr/img/hero/';
      $images = [
        'desktop' => file_create_url($hero_path . 'hero-1400.jpg'),
        'desktop2x' => file_create_url($hero_path . 'hero-1400x2.jpg'),
        'mobile' => file_create_url($hero_path . 'hero-mobile.jpg'),
        'mobile2x' => file_create_url($hero_path . 'hero-mobilex2.jpg'),
      ];
      $build['#content'] = array_merge($build['#content'], ['images' => $images]);
    }
    return $build;
  }

}
