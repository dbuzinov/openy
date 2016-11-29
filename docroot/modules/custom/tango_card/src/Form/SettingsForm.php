<?php

namespace Drupal\tango_card\Form;

use Drupal\Core\Url;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\tango_card\TangoCardWrapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Tango Card settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Construct SettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheTagsInvalidatorInterface $cache_tags_invalidator
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('cache_tags.invalidator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tango_card_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['tango_card.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('tango_card.settings');

    $form['app_mode'] = [
      '#title' => $this->t('Application mode'),
      '#type' => 'radios',
      '#options' => [
        'sandbox' => $this->t('Sandbox'),
        'production' => $this->t('Production'),
      ],
      '#default_value' => $config->get('app_mode'),
      '#required' => TRUE,
    ];

    $form['platform'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Platform credentials'),
    ];

    $fields = ['platform_name' => 'Name', 'platform_key' => 'Key'];
    foreach ($fields as $field => $title) {
      $form['platform'][$field] = [
        '#type' => 'textfield',
        '#title' => $this->t($title),
        '#default_value' => $config->get($field),
        '#required' => TRUE,
      ];
    }

    $link_title = $this->t('here');
    $fields = [
      'account' => [
        'title' => 'Default Tango Card account',
        'description' => 'The default Tango Account to use on requests. To manage accounts, click <a href=":url">here</a>.',
      ],
      'campaign' => [
        'title' => 'Default campaign',
        'description' => 'The default campaign to use on requests. A campaign contains settings like email template and notification message. To manage campaigns, click <a href=":url">here</a>.',
      ],
    ];

    foreach ($fields as $field => $info) {
      $entity_type = 'tango_card_' . $field;

      if ($entity = $config->get($field)) {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity);
      }

      $form[$field] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t($info['title']),
        '#target_type' => $entity_type,
        '#default_value' => $entity,
        '#description' => $this->t($info['description'], [
          ':url' => Url::fromRoute('entity.' . $entity_type . '.collection')->toString(),
        ]),
      ];
    }

    if (!$form['platform']['platform_key']['#default_value']) {
      $form['account']['#access'] = FALSE;
      $form['campaign']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->cacheTagsInvalidator->invalidateTags(['tango_card']);

    $fields = [
      'campaign',
      'account',
      'app_mode',
      'platform_name',
      'platform_key',
    ];

    $config = $this->config('tango_card.settings');
    foreach ($fields as $field) {
      $config->set($field, $form_state->getValue($field));
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
