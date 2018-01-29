<?php

namespace Drupal\ymca_camp_du_nord\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Views;
use Drupal\Core\Url;

/**
 * Implements Cdn Form Full.
 */
class CdnFormFull extends FormBase {

  /**
   * The entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $ajaxOptions;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The state of form.
   *
   * @var array
   */
  protected $state;

  /**
   * CdnFormFull constructor.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The entity query factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The entity type manager.
   */
  public function __construct(QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ymca_camp_du_nord');

    $this->villageOptions = $this->getVillageOptions();
    $this->capacityOptions = $this->getCapacityOptions();

    $query = $this->getRequest()->query->all();

    $tz = new \DateTimeZone(\Drupal::config('system.date')->get('timezone.default'));
    $default_arrival_date = NULL;
    if (!empty($query['arrival_date'])) {
      $dt = new \DateTime($query['arrival_date'], $tz);
      $default_arrival_date = $dt->format('Y-m-d');
    }
    else {
      $dt = new \DateTime();
      $dt->setTimezone($tz);
      $dt->setTimestamp(REQUEST_TIME + (86400 * 3));
      $default_arrival_date = $dt->format('Y-m-d');
    }
    $default_departure_date = NULL;
    if (!empty($query['departure_date'])) {
      $dt = new \DateTime($query['departure_date'], $tz);
      $default_departure_date = $dt->format('Y-m-d');
    }
    else {
      $dt = new \DateTime();
      $dt->setTimezone($tz);
      $dt->setTimestamp(REQUEST_TIME + (86400 * 7));
      $default_departure_date = $dt->format('Y-m-d');
    }
    $state = [
      'ids' => isset($query['ids']) && is_numeric($query['ids']) ? $query['ids'] : '',
      'village' => isset($query['village']) && is_numeric($query['village']) ? $query['village'] : 'all',
      'arrival_date' => isset($query['arrival_date']) ? $query['arrival_date'] : $default_arrival_date,
      'departure_date' => isset($query['departure_date']) ? $query['departure_date'] : $default_departure_date,
      'range' => isset($query['range']) ? $query['range'] : 3,
      'capacity' => isset($query['capacity']) ? $query['capacity'] : 'all',
      'cid' => isset($query['cid']) ? $query['cid'] : '',
      'show' => isset($query['show']) ? $query['show'] : 'all',
    ];

    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'cdn_form_full';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $locations = []) {
    $state = $this->state;
    $formatted_results = NULL;

    $formatted_results = self::buildResults($form, $form_state);

    $form['#prefix'] = '<div id="cdn-full-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['ids'] = [
      '#type' => 'hidden',
      '#value' => $state['ids'],
    ];

    $form['arrival_date'] = [
      '#type' => 'date',
      '#title' => t('Check-in Date'),
      '#prefix' => '<div class="top-elements-wrapper"><div class="container"><h2>' . $this->t('Search') . '</h2>',
      '#default_value' => $state['arrival_date'],
    ];

    $form['departure_date'] = [
      '#type' => 'date',
      '#title' => t('Check-out Date'),
      '#default_value' => $state['departure_date'],
    ];

    $form['range'] = [
      '#type' => 'select',
      '#title' => t('Expand date range by'),
      '#default_value' => !is_null($state['range']) ? $state['range'] : 3,
      '#options' => [
        0 => '+/- 0 Days',
        3 => '+/- 3 Days',
        7 => '+/- 7 Days',
        10 => '+/- 10 Days'
      ],
    ];

    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      // Close top-elements-wrapper.
      '#suffix' => '</div></div>',
      '#button_type' => 'primary',
    );

    $form['village'] = [
      '#type' => 'select',
      '#prefix' => '<div class="bottom-elements-wrapper"><div class="container">',
      '#title' => t('By village'),
      '#default_value' => $state['village'],
      '#options' => $this->villageOptions,
    ];

    $form['capacity'] = [
      '#type' => 'select',
      '#title' => t('Capacity'),
      '#default_value' => $state['capacity'],
      '#options' => $this->capacityOptions,
      '#suffix' => '<p class="help-tip">' . t('Please scroll down and select dates on calendar *') . '</p>',
    ];

    $form['results'] = [
      // Close bottom-elements-wrapper.
      '#prefix' => '</div></div><div class="cdn-results">',
      '#markup' => render($formatted_results),
      '#suffix' => '</div>',
      '#weight' => 10,
    ];

    $form['#attached']['library'][] = 'ymca_camp_du_nord/cdn';

    $form['#cache'] = [
      'max-age' => 0,
    ];

    return $form;
  }

  /**
   * Custom ajax callback.
   */
  public function rebuildAjaxCallback(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $state = $this->state;

    $formatted_results = self::buildResults($form, $form_state);
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#cdn-full-form-wrapper .cdn-results', $formatted_results));

    $form_state->setRebuild();
    return $response;
  }

  /**
   * Build results.
   */
  public function buildResults(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    $values = $form_state->getValues();
    $query = $this->state;

    $cdn_product_ids = $this->entityQuery
      ->get('cdn_prs_product')
      ->condition('field_cdn_prd_start_date', '%' . $query['arrival_date'] . '%', 'LIKE')
      ->execute();
    // Try to iterate range if start date is not available.
    if (empty($cdn_product_ids)) {
      $arrival_date = new \DateTime($query['arrival_date']);
      $departure_date = new \DateTime($query['departure_date']);
      for ($i = 0; $i < $query['range']; $i++) {
        // @todo: limit to past dates.
        $arrival_date->modify('- 1 day');
        $departure_date->modify('+ 1 day');
      }
      $period = new \DatePeriod(
        $arrival_date,
        new \DateInterval('P1D'),
        $departure_date
      );
      foreach ($period as $date) {
        if (!empty($cdn_product_ids)) {
          continue;
        }
        $cdn_product_ids = $this->entityQuery
          ->get('cdn_prs_product')
          ->condition('field_cdn_prd_start_date', '%' . $date->format('Y-m-d') . '%', 'LIKE')
          ->execute();
      }
    }
    $formatted_results = '<div class="container">' . $this->t('Please select another village, change capacity or date range to see if there are other cabins available.') . '</div>';
    if ($cdn_products = $this->entityTypeManager->getStorage('cdn_prs_product')->loadMultiple($cdn_product_ids)) {
      if ($query['village'] !== 'all' || $query['capacity'] !== 'all') {
        foreach ($cdn_products as $key => $product) {
          $capacity = $product->field_cdn_prd_capacity->value;
          $cabin_id = $product->field_cdn_prd_cabin_id->value;
          // Filter by capacity.
          if ($query['capacity'] !== $capacity && $query['capacity'] !== 'all') {
            unset($cdn_products[$key]);
          }
          // Filter by village.
          if (!empty($cabin_id)) {
            $mapping_id = $this->entityQuery
              ->get('mapping')
              ->condition('type', 'cdn_prs_product')
              ->condition('field_cdn_prd_cabin_id', $cabin_id)
              ->execute();
            $mapping_id = reset($mapping_id);
            if ($mapping = $this->entityTypeManager->getStorage('mapping')->load($mapping_id)) {
              if (!$mapping->field_cdn_prd_village_ref->isEmpty()) {
                $ref = $mapping->field_cdn_prd_village_ref->getValue();
                $page_id = isset($ref[0]['target_id']) ? $ref[0]['target_id'] : FALSE;
                // Filter by village.
                if ($page_id !== $query['village'] && $query['village'] !== 'all') {
                  unset($cdn_products[$key]);
                }
              }
            }
          }
        }
      }
      if (!empty($cdn_products)) {
        $formatted_results = $this->buildResultsLayout($cdn_products, $query, $user_input);
      }
    }
    return $formatted_results;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $today = new DrupalDateTime();
    $today_modified = new DrupalDateTime('+ 3 days');
    $arrival_date = $form_state->getValue('arrival_date');
    $departure_date = $form_state->getValue('departure_date');
    $arrival_date = DrupalDateTime::createFromFormat('Y-m-d', $arrival_date);
    $departure_date = DrupalDateTime::createFromFormat('Y-m-d', $departure_date);
    // Check if range less than 30 days.
    $range = $departure_date->diff($arrival_date);
    if ($range->days > 30) {
      $form_state->setErrorByName('departure_date', t('Please select less than 30 days for date range.'));
    }
    // Check if arrival date less than today + 3 days.
    if ($today_modified > $arrival_date) {
      $form_state->setErrorByName('arrival_date', t('Arrival date should not be less than today + 3 days.'));
    }
    // Check if arrival date less than departure.
    if ($arrival_date >= $departure_date) {
      $form_state->setErrorByName('departure_date', t('Departure date should not be less than arrival date'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $parameters = [];
    unset($values['submit']);
    unset($values['form_build_id']);
    unset($values['form_token']);
    unset($values['op']);
    unset($values['form_id']);
    $route = \Drupal::routeMatch()->getRouteName();
    $node = \Drupal::routeMatch()->getParameter('node');
    if ($route == 'entity.node.canonical') {
      $parameters = [
        'node' => $node->id(),
      ];
    }
    $form_state->setRedirect(
      $route,
      $parameters,
      ['query' => $values]
    );
  }

  /**
   * Return Village options.
   */
  public function getVillageByCabinId($cid) {
    $village_id = '';
    $mapping_ids = $this->entityQuery
      ->get('mapping')
      ->condition('type', 'cdn_prs_product')
      ->condition('field_cdn_prd_cabin_id', $cid)
      ->execute();
    $mapping_id = reset($mapping_ids);
    if ($mapping = $this->entityTypeManager->getStorage('mapping')->load($mapping_id)) {
      $village_id = !$mapping->field_cdn_prd_village_ref->isEmpty() ? $mapping->field_cdn_prd_village_ref->target_id : '';
    }
    return $village_id;
  }

  /**
   * Return Village options.
   */
  public function getVillageOptions() {
    $options = ['all' => t('Show All')];
    $mapping_ids = $this->entityQuery
      ->get('mapping')
      ->condition('type', 'cdn_prs_product')
      ->execute();
    if ($mappings = $this->entityTypeManager->getStorage('mapping')->loadMultiple($mapping_ids)) {
      foreach ($mappings as $mapping) {
        $ref = $mapping->field_cdn_prd_village_ref->getValue();
        $page_id = isset($ref[0]['target_id']) ? $ref[0]['target_id'] : FALSE;
        if ($page_node = $this->entityTypeManager->getStorage('node')->load($page_id)) {
          $options[$page_id] = $page_node->getTitle();
        }
      }
    }
    return $options;
  }

  /**
   * Return Capacity options.
   */
  public function getCapacityOptions() {
    $options = ['all' => t('Show All')];
    $cdn_products_ids = $this->entityQuery
      ->get('cdn_prs_product')
      ->execute();
    if ($cdn_products = $this->entityTypeManager->getStorage('cdn_prs_product')->loadMultiple($cdn_products_ids)) {
      foreach ($cdn_products as $cdn_product) {
        $value = $cdn_product->field_cdn_prd_capacity->getValue();
        if (!empty($value[0]['value'])) {
          $options[$value[0]['value']] = $value[0]['value'] . ' ' . t('people');
        }
      }
    }
    ksort($options);
    return $options;
  }

  /**
   * Helper method to make results layout.
   *
   * @param array $cdn_products
   *   Fetched products.
   *
   * @return array
   *   Results render array.
   */
  public function buildResultsLayout(array $cdn_products, $query, $user_input) {
    $cabins_ids = $cabins = $attached = $results = $teasers = [];
    $cache = [
      'max-age' => 0,
    ];
    $default_availability = t('Available');
    if (!empty($cdn_products)) {
      // Load all the cabins for provided start date.
      foreach ($cdn_products as $product) {
        $cabins_ids[] = $product->field_cdn_prd_cabin_id->value;
      }
      // Load all the info for loaded cabins.
      $mapping_ids = $this->entityQuery
        ->get('mapping')
        ->condition('type', 'cdn_prs_product')
        ->condition('field_cdn_prd_cabin_id', $cabins_ids, 'IN')
        ->sort('name')
        ->execute();
      if ($mappings = $this->entityTypeManager->getStorage('mapping')->loadMultiple($mapping_ids)) {
        $total_capacity = $cabin_url = $image = $panorama = $description = '';
        foreach ($mappings as $mapping) {
          $cid = $mapping->field_cdn_prd_cabin_id->value;
          $cabin_url_query = $query;
          $cabin_url_query['cid'] = $cid;
          $cabin_url_query['show'] = 'all';
          $cabin_url = Url::fromUri('internal:/camps/camp_du_nord/search/form', [
            'query' => $cabin_url_query,
          ]);
          $description = !$mapping->field_cdn_prd_cabin_desc->isEmpty() ? $mapping->field_cdn_prd_cabin_desc->view('default') : '';
          $panorama = !$mapping->field_cdn_prd_panorama->isEmpty() ? $mapping->field_cdn_prd_panorama->view('default') : '';
          $fid = !$mapping->field_cdn_prd_image->isEmpty() ? $mapping->field_cdn_prd_image->target_id : '';
          if ($file = $this->entityTypeManager->getStorage('file')->load($fid)) {
            $image = file_create_url($file->getFileUri());
          }
          else {
            // Provide default image.
            $image = base_path() . drupal_get_path('module', 'ymca_camp_du_nord') . '/assets/cabin3.png';
          }
          // Load a product for additional details.
          $product_id = $this->entityQuery
            ->get('cdn_prs_product')
            ->condition('field_cdn_prd_cabin_id', $cid)
            ->range(0, 1)
            ->execute();
          $product_id = reset($product_id);
          if ($product = $this->entityTypeManager->getStorage('cdn_prs_product')->load($product_id)) {
            $total_capacity = !$product->field_cdn_prd_capacity->isEmpty() ? $product->field_cdn_prd_capacity->value : '';
          }
          $cabins[$cid] = [
            'start_product' => $product,
            'teaser' => [
              '#theme' => 'cdn_village_teaser',
              '#title' => $mapping->getName(),
              '#description' => $description,
              '#panorama' => $panorama,
              '#image' => $image,
              '#availability' => $default_availability,
              '#capacity' => $total_capacity,
              '#cabin_url' => $cabin_url,
              '#cache' => $cache,
            ],
          ];
        }
      }

      $first = '';
      $skip = FALSE;
      foreach ($cabins as $cabin) {
        $product = $cabin['start_product'];
        // Fill all the cabins with data.
        $teasers[$product->field_cdn_prd_cabin_id->value] = [
          'teaser' => $cabins[$product->field_cdn_prd_cabin_id->value],
        ];
        // Use first product if cid is not provided.
        if (empty($this->state['cid']) && empty($first)) {
          $first = $product->field_cdn_prd_cabin_id->value;
        }
        if (empty($this->state['cid']) && $product->field_cdn_prd_cabin_id->value !== $first) {
          $skip = TRUE;
        }
        // Use chosen cabin.
        if (!empty($this->state['cid']) && $this->state['cid'] !== 'all' && $product->field_cdn_prd_cabin_id->value !== $this->state['cid']) {
          $skip = TRUE;
        }
        if ($skip) {
          $teasers[$product->field_cdn_prd_cabin_id->value]['teaser']['teaser']['#panorama'] = '';
          $teasers[$product->field_cdn_prd_cabin_id->value]['teaser']['teaser']['#description'] = '';
          continue;
        }
        $code = $product->field_cdn_prd_code->value;
        $code = substr($code, 0, 14);
        $arrival_date = new \DateTime($query['arrival_date']);
        //$arrival_date->modify('+ 3 days');
        $departure_date = new \DateTime($query['departure_date']);
        if (!empty($query['range'])) {
          for ($i = 0; $i < $query['range']; $i++) {
            // @todo: limit to past dates.
            $today = new DrupalDateTime();
            $arrival_date->modify('- 1 day');
            $departure_date->modify('+ 1 day');
          }
        }
        $period = new \DatePeriod(
          $arrival_date,
          new \DateInterval('P1D'),
          $departure_date
        );
        $codes = [];
        $attached['drupalSettings']['cdn']['selected_dates'] = [];
        foreach ($period as $date) {
          $codes[] = $code . $date->format('mdy') . '_YHL';
          $attached['drupalSettings']['cdn']['selected_dates'][] = $date->format('Y-m-d');
        }
        $codes[] = $code . $date->modify('+ 1 day')->format('mdy') . '_YHL';
        $attached['drupalSettings']['cdn']['selected_dates'][] = $date->format('Y-m-d');
        // Load calendar view with all dates for a product.
        $args = [implode(',', $codes)];
        $view = Views::getView('cdn_calendar');
        if (is_object($view)) {
          $view->setArguments($args);
          $view->setDisplay('embed_1');
          $view->preExecute();
          $view->execute();
        }
        $calendar_list_data = $this->buildListCalendarAndFooter($view);
        $calendar = $view->buildRenderable('embed_1', $args);

        $teasers[$product->field_cdn_prd_cabin_id->value] += [
          'calendar' => [
            'list' => $calendar_list_data['list'],
            'calendar' => $calendar
          ],
          'footer' => $calendar_list_data['footer'],
        ];
      }

      $results = [
        '#theme' => 'cdn_results_wrapper',
        '#teasers' => $teasers,
        '#cache' => $cache,
        '#attached' => $attached,
      ];
    }

    return $results;
  }

  /**
   * Helper method to create mobile view calendar.
   *
   * @param array $view
   *   Fetched view with products.
   *
   * @return array
   *   Results render array.
   */
  public function buildListCalendarAndFooter($view) {
    $product_ids = $builds = [];
    $total_nights = 0;
    $total_price = '';
    // Collect products ids.
    $product_ids = [];
    foreach ($view->result as $row) {
      $product_ids[] = !$row->_entity->field_cdn_prd_id->isEmpty() ? $row->_entity->field_cdn_prd_id->value : '';
    }
    // Check availability for given products.
    if (!empty($product_ids)) {
      $products = \Drupal::service('ymca_cdn_sync.add_to_cart')->checkProductAvailability($product_ids);
    }
    foreach ($view->result as $row) {
      $entity = $row->_entity;
      $cid = !$entity->field_cdn_prd_cabin_id->isEmpty() ? $entity->field_cdn_prd_cabin_id->value : '';
      $product_id = !$entity->field_cdn_prd_id->isEmpty() ? $entity->field_cdn_prd_id->value : '';
      $date = !$entity->field_cdn_prd_start_date->isEmpty() ? $entity->field_cdn_prd_start_date->value : '';
      $price = !$entity->field_cdn_prd_list_price->isEmpty() ? $entity->field_cdn_prd_list_price->value : '';
      $total_capacity = !$entity->field_cdn_prd_capacity->isEmpty() ? $entity->field_cdn_prd_capacity->value : '';
      $capacity = !$entity->field_cdn_prd_capacity_left->isEmpty() ? $entity->field_cdn_prd_capacity_left->value : '';
      $registrations = !$entity->field_cdn_prd_regs->isEmpty() ? $entity->field_cdn_prd_regs->value : '';
      $pid = !$entity->field_cdn_prd_id->isEmpty() ? $entity->field_cdn_prd_id->value : '';
      $village_id = $this->getVillageByCabinId($cid);
      // Check if cabin is booked.
      $is_booked = FALSE;
      if ($capacity - $registrations == 0) {
        $is_booked = TRUE;
      }
      // Additional check from live results if they were provided.
      if (!empty($products[$pid])) {
        $is_booked = !$products[$pid]['available'];
      }
      $date = substr($date, 0, 10);
      $date1 = DrupalDateTime::createFromFormat('Y-m-d', $date)->format('F');
      $date2 = DrupalDateTime::createFromFormat('Y-m-d', $date)->format('d');
      $date3 = DrupalDateTime::createFromFormat('Y-m-d', $date)->format('D');
      $builds['list'][] = [
        '#theme' => 'cdn_results_calendar',
        '#data' => [
          'id' => $entity->id(),
          'pid' => $pid,
          'date1' => $date1,
          'date2' => $date2,
          'date3' => $date3,
          'is_booked' => $is_booked,
          'is_selected' => FALSE,
          'price' => $price,
          'total_capacity' => $total_capacity,
          'village_id' => $village_id,
        ],
      ];
      $total_price += $price;
      $total_nights++;
      $product_ids[] = $product_id;
    }
    $login_url = Url::fromUri('internal:/cdn/personify/login', ['query' => ['ids' => implode(',', $product_ids)]])->toString();
    $builds['footer'] = [
      'total_nights' => $total_nights,
      'total_price' => $total_price,
      'login_url' => $login_url,
    ];
    return $builds;
  }

}
