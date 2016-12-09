<?php

namespace Drupal\ymca_retention\Form;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ymca_retention\RegularUpdater;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides form for managing module cron settings.
 */
class SettingsCronForm extends FormBase {

  /**
   * The regular updater.
   *
   * @var \Drupal\ymca_retention\RegularUpdater
   */
  protected $regularUpdater;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * SettingsCronForm constructor.
   *
   * @param \Drupal\ymca_retention\RegularUpdater $regular_updater
   *   The regular updater service.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter.
   */
  public function __construct(RegularUpdater $regular_updater, DateFormatter $date_formatter) {
    $this->regularUpdater = $regular_updater;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ymca_retention.regular_updater'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ymca_retention_cron_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ymca_retention.cron_settings');

    $date_from = new \DateTime();
    $date_from->setTime(0, 0, 0);
    $form['date_from'] = [
      '#type' => 'datetime',
      '#required' => TRUE,
      '#title' => $this->t('Date From'),
      '#default_value' => DrupalDateTime::createFromDateTime($date_from),
      '#description' => $this->t('Specify a date from what need to import checkins. Currently we support only 1 day import.'),
    ];
    $date_from->setTime(23, 59, 59);
    $form['date_to'] = [
      '#type' => 'datetime',
      '#required' => TRUE,
      '#title' => $this->t('Date To'),
      '#default_value' => DrupalDateTime::createFromDateTime($date_from),
      '#description' => $this->t('Specify a date to what need to import checkins. Currently we support only 1 day import.'),
    ];
    $last_run = $this->dateFormatter->format($config->get('last_run'), 'long');

    $form['last_run'] = [
      '#type' => '#markup',
      '#markup' => $this->t('Queue last created: %timestamp', [
        '%timestamp' => $last_run,
      ]),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Create a queue'),
      '#submit' => [$this, '::createQueueSubmitForm'],
      '#button_type' => 'primary',
    );
    if (\Drupal::moduleHandler()->moduleExists('queue_ui')) {
      $form['actions']['run_queue'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Run queue'),
        '#submit' => [$this, '::runQueueSubmitForm'],
        '#button_type' => 'secondary',
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $button = $form_state->getTriggeringElement();
    if ($button['#button_type'] != 'primary') {
      return;
    }
    /* @var DrupalDateTime $date_from */
    $date_from = $form_state->getValue('date_from');
    /* @var DrupalDateTime $date_to */
    $date_to = $form_state->getValue('date_to');
    $diff = $date_from->diff($date_to);
    if ($diff->days >= 1) {
      $form_state->setErrorByName('date_to', $this->t('Interval of dates should not be more than 1 day.'));
    }
  }

  /**
   * Create a queue.
   *
   * @param array $form
   *   Form.
   * @param FormStateInterface $form_state
   *   Form state.
   */
  public function createQueueSubmitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->regularUpdater->isAllowed(TRUE)) {
      drupal_set_message($this->t('Creating queue is not allowed now. Queue is already exist or campaign settings does not allow create queue.'), 'error');
      return;
    }
    /* @var DrupalDateTime $date_from */
    $date_from = $form_state->getValue('date_from');
    /* @var DrupalDateTime $date_to */
    $date_to = $form_state->getValue('date_to');

    $this->regularUpdater->createQueue($date_from->getTimestamp(), $date_to->getTimestamp());
    drupal_set_message($this->t('Queue successfully created.'));
  }

  /**
   * Execute a queue.
   *
   * @param array $form
   *   Form.
   * @param FormStateInterface $form_state
   *   Form state.
   */
  public function runQueueSubmitForm(array &$form, FormStateInterface $form_state) {
    if (!\Drupal::moduleHandler()->moduleExists('queue_ui')) {
      return;
    }
    // Process queue with batch.
    $queue = \Drupal::queue('ymca_retention_updates_member_visits');
    $batch = [
      'operations' => [],
    ];
    foreach (range(1, $queue->numberOfItems()) as $index) {
      $batch['operations'][] = [
        '\Drupal\queue_ui\QueueUIBatch::step',
        ['ymca_retention_updates_member_visits'],
      ];
    }
    batch_set($batch);
    drupal_set_message($this->t('Queue has been executed.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) { }

}
