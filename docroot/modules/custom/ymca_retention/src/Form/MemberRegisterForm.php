<?php

namespace Drupal\ymca_retention\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ymca_retention\Ajax\YmcaRetentionModalSetContent;
use Drupal\ymca_retention\Ajax\YmcaRetentionSetTab;
use Drupal\ymca_retention\AnonymousCookieStorage;
use Drupal\ymca_retention\Entity\Member;
use Drupal\ymca_retention\PersonifyApi;
use Drupal\ymca_mappings\LocationMappingRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member registration form.
 */
class MemberRegisterForm extends FormBase {

  /**
   * The location mapping repository.
   *
   * @var \Drupal\ymca_mappings\LocationMappingRepository
   */
  protected $locationRepository;

  /**
   * MemberRegisterForm constructor.
   *
   * @param \Drupal\ymca_mappings\LocationMappingRepository $location_repository
   *   The location mapping repository.
   */
  public function __construct(LocationMappingRepository $location_repository) {
    $this->locationRepository = $location_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ymca_mappings.location_repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ymca_retention_register_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['args'][0];
    if (isset($config['theme'])) {
      $form['#theme'] = $config['theme'];
    }

    // TODO: leave comment explaining what this tab_id is.
    if (!$tab_id = $form_state->get('tab_id')) {
      $tab_id = 'about';
    }
    $form['tab_id'] = ['#type' => 'hidden', '#default_value' => $tab_id];

    // Set the flag if user accessed the page with "mobile" query parameter.
    $form['created_on_mobile'] = [
      '#type' => 'hidden',
      '#value' => array_key_exists('mobile', $_GET) && $_GET['mobile'] ? 1 : 0,
    ];

    $membership_id = $form_state->get('membership_id');
    $personify_email = $form_state->get('personify_email');
    $step_value = $form_state->getTemporaryValue('step');

    // Determine step of the form - which screen to show.
    // 1 - enter Member ID;
    // 2 - confirm email address from Personify;
    // 3 - manually enter email address.
    if ($step_value) {
      $step = $step_value;
    }
    elseif (empty($membership_id)) {
      $step = 1;
    }
    else {
      if (empty($personify_email)) {
        $step = 3;
      }
      else {
        $step = 2;
      }
    }
    // If it is registration by YTeam - then the step is always 1.
    if ($config['yteam']) {
      $step = 1;
    }
    $form['step'] = array(
      '#type' => 'hidden',
      '#value' => $step,
    );

    $validate_required = [get_class($this), 'elementValidateRequired'];

    if ($step == 1) {
      $form['membership_id'] = [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#attributes' => [
          'placeholder' => [
            $config['yteam'] ? $this->t('Member ID') : $this->t('Your member ID'),
          ],
        ],
        '#element_required_error' => $this->t('Member ID is required.'),
        '#element_validate' => [
          $validate_required,
        ],
        '#skip_ymca_preprocess' => TRUE,
      ];
    }

    if ($step == 2 || $step == 3) {
      $form['email'] = [
        '#type' => 'email',
        '#title' => $this->t('Email'),
        '#title_display' => 'hidden',
        '#required' => TRUE,
        '#attributes' => [
          'placeholder' => [
            $this->t('Your email'),
          ],
        ],
        '#element_required_error' => $this->t('Email is required.'),
        '#element_validate' => [
          ['\Drupal\Core\Render\Element\Email', 'validateEmail'],
          $validate_required,
        ],
        '#skip_ymca_preprocess' => TRUE,
      ];
      if ($step == 2) {
        $form['email']['#default_value'] = $personify_email;
        $form['email']['#attributes']['disabled'] = TRUE;
      }
    }

    $ajax = [
      'callback' => [$this, 'ajaxFormCallback'],
      'method' => 'replaceWith',
      'wrapper' => isset($config['wrapper']) ? $config['wrapper'] : 'registration .registration-form form',
      'progress' => [
        'type' => 'throbber',
        'message' => NULL,
      ],
    ];
    $form['submit_ok'] = [
      '#type' => 'submit',
      '#name' => 'submit_ok',
      '#value' => $config['yteam'] ? $this->t('Register') : $this->t('OK'),
      '#attributes' => [
        'class' => [
          'btn',
          'btn-lg',
          'btn-primary',
          $config['yteam'] ? 'compain-dark-green' : 'compain-green',
        ],
      ],
      '#ajax' => $ajax,
    ];
    if ($step == 2) {
      $form['submit_ok']['#value'] = $this->t('Yes, all fine');
      $form['submit_ok']['#attributes']['class'][] = 'pull-left';
    }
    if ($step == 3) {
      $form['submit_ok']['#value'] = $this->t('OK');
    }

    if ($step == 2) {
      $form['submit_change'] = [
        '#type' => 'submit',
        '#name' => 'submit_change',
        '#value' => $this->t('No, change'),
        '#attributes' => [
          'class' => [
            'btn',
            'btn-lg',
            'btn-primary',
            'compain-grey',
            'pull-right',
          ],
        ],
        '#ajax' => $ajax,
      ];
    }

    return $form;
  }

  /**
   * Set a custom validation error on the #required element.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function elementValidateRequired(array $element, FormStateInterface $form_state) {
    if (!empty($element['#required_but_empty']) && isset($element['#element_required_error'])) {
      $form_state->setError($element, $element['#element_required_error']);
    }
  }

  /**
   * Ajax form callback for displaying errors or redirecting.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|array
   *   Ajax response.
   */
  public function ajaxFormCallback(array &$form, FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['args'][0];
    if (!$config['yteam'] && $form_state->isExecuted()) {
      // Instantiate an AjaxResponse Object to return.
      $ajax_response = new AjaxResponse();

      $ajax_response->addCommand(new YmcaRetentionSetTab($form_state->getValue('tab_id')));
      $ajax_response->addCommand(new YmcaRetentionModalSetContent('ymca-retention-user-menu-reg-confirmation-form'));

      $this->refreshValues($form_state);
      $new_form = \Drupal::formBuilder()
        ->rebuildForm($this->getFormId(), $form_state, $form);

      // Refreshing form.
      $ajax_response->addCommand(new HtmlCommand('#ymca-retention-user-menu-register-form', $new_form));

      return $ajax_response;
    }

    if ($form_state->hasAnyErrors()) {
      $form['messages'] = ['#type' => 'status_messages'];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element['#name'] == 'submit_change') {
      $form_state->setTemporaryValue('step', 3);
      $form_state->setRebuild();
      return;
    }

    $config = $form_state->getBuildInfo()['args'][0];
    $membership_id = $form_state->get('membership_id');
    $personify_member = $form_state->get('personify_member');
    $personify_email = $form_state->get('personify_email');

    // Get retention settings.
    $settings = \Drupal::config('ymca_retention.general_settings');
    $open_date = new \DateTime($settings->get('date_registration_open'));
    $close_date = new \DateTime($settings->get('date_registration_close'));
    $current_date = new \DateTime();

    // Validate dates.
    if ($current_date < $open_date) {
      $form_state->setErrorByName('form', $this->t('Registration begins %date when the Y spirit challenge open.', [
        '%date' => $open_date->format('F j'),
      ]));
      return;
    }
    if ($current_date > $close_date) {
      $form_state->setErrorByName('form', $this->t('The Y spirit challenge is now closed and registration is no longer able to be tracked.'));
      return;
    }

    // Check for already registered member.
    $query = \Drupal::entityQuery('ymca_retention_member')
      ->condition('membership_id', $membership_id);
    $result = $query->execute();
    if (!empty($result)) {
      $form_state->setErrorByName('membership_id', $this->t('The member ID is already registered. Please log in.'));
      return;
    }
    if (empty($membership_id)) {
      $membership_id = trim($form_state->getValue('membership_id'));
      $form_state->set('membership_id', $membership_id);
    }

    if (empty($personify_member)) {
      // Get information about member from Personify and validate entered membership ID.
      $personify_result = PersonifyApi::getPersonifyMemberInformation($membership_id);
      if (empty($personify_result)
        || !empty($personify_result->ErrorMessage)
        || empty($personify_result->BranchId) || (int) $personify_result->BranchId == 0
      ) {
        $form_state->setErrorByName('membership_id', $this->t('Sorry, we can\'t locate this member ID. Please call 612-230-9622 or stop by your local Y if you need assistance.'));
        return;
      }
      elseif ($config['yteam'] && empty($personify_result->PrimaryEmail)) {
        $form_state->setErrorByName('membership_id', $this->t('Sorry, we don\'t have email address to register this user.'));
        return;
      }
      else {
        $form_state->set('personify_member', $personify_result);
        $email = Unicode::strtolower($personify_result->PrimaryEmail);
        $email = ymca_retention_clean_personify_email($email);
        $form_state->set('personify_email', $email);
        if ($config['yteam']) {
          $form_state->set('email', $form_state->get('personify_email'));
        }
        else {
          $form_state->setRebuild();
        }
        return;
      }
    }

    // Either use email address from Personify or manually entered email address.
    $submitted_email = trim($form_state->getValue('email'));
    $form_state->set('email', $submitted_email);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['args'][0];
    $personify_member = $form_state->get('personify_member');

    $query = \Drupal::entityQuery('ymca_retention_member')
      ->condition('personify_id', $personify_member->MasterCustomerId);
    $result = $query->execute();

    if (empty($result)) {
      $entity = $this->createEntity($form_state);
    }
    else {
      $entity = $this->updateEntity(key($result), $form_state);
    }

    if ($config['yteam']) {
      $this->refreshValues($form_state);
      $form_state->setRebuild();

      drupal_set_message($this->t('Registered @full_name with email address @email.', [
        '@full_name' => $entity->getFullName(),
        '@email' => $this->obfuscateEmail($entity->getEmail()),
      ]));
    }
    else {
      AnonymousCookieStorage::set('ymca_retention_member', $entity->getId());
      $form_state->setRebuild();
    }
  }

  /**
   * Create member entity.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\ymca_retention\Entity\Member
   *   Member entity.
   */
  protected function createEntity(FormStateInterface $form_state) {
    $config = $form_state->getBuildInfo()['args'][0];
    // Get form values.
    $membership_id = $form_state->get('membership_id');
    $personify_member = $form_state->get('personify_member');
    $personify_email = $form_state->get('personify_email');

    // Get retention settings.
    $settings = \Drupal::config('ymca_retention.general_settings');

    // Calculate visit goal.
    $visit_goal = 0;
    if ($settings->get('calculate_visit_goal')) {
      $visit_goal = $this->calculateVisitGoal($membership_id, $settings);
    }

    // Get information about number of checkins in period of campaign.
    $from = $settings->get('date_reporting_open');
    $to = $settings->get('date_reporting_close');
    $current_result = PersonifyApi::getPersonifyVisitCountByDate($membership_id, $from, $to);

    $total_visits = 0;
    if (empty($current_result->ErrorMessage) && $current_result->TotalVisits > 0) {
      $total_visits = $current_result->TotalVisits;
    }

    // Identify if user is employee or not.
    $is_employee = !empty($personify_member->ProductCode) && strpos($personify_member->ProductCode, 'STAFF');

    $location = $this->locationRepository->findByLocationPersonifyBranchCode($personify_member->BranchId);
    if (is_array($location)) {
      $location = key($location);
    }

    // Create a new entity.
    /** @var Member $entity */
    $entity = \Drupal::entityTypeManager()
      ->getStorage('ymca_retention_member')
      ->create([
        'membership_id' => $membership_id,
        'personify_id' => $personify_member->MasterCustomerId,
        'mail' => $form_state->get('email'),
        'personify_email' => $personify_email,
        'first_name' => $personify_member->FirstName,
        'last_name' => $personify_member->LastName,
        'birth_date' => $personify_member->BirthDate,
        'branch' => (int) $location,
        'is_employee' => $is_employee,
        'visit_goal' => $visit_goal,
        'total_visits' => $total_visits,
        'created_by_staff' => $config['yteam'],
        'created_on_mobile' => $form_state->getValue('created_on_mobile'),
      ]);
    $entity->save();

    return $entity;
  }

  /**
   * Update member entity.
   *
   * @param int $entity_id
   *   Entity id.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\ymca_retention\Entity\Member
   *   Member entity.
   */
  protected function updateEntity($entity_id, FormStateInterface $form_state) {
    $entity = Member::load($entity_id);

    // Update member email.
    $entity->setEmail($form_state->get('email'));
    $entity->save();

    return $entity;
  }

  /**
   * Calculate visit goals.
   *
   * @param string $membership_id
   *   Membership ID - Facility access ID.
   *
   * @return array
   *   Visit goal and total visits.
   */
  protected function calculateVisitGoal($membership_id, $settings) {
    // Get information about number of checkins before campaign.
    $current_date = new \DateTime();
    $from_date = new \DateTime($settings->get('date_checkins_start'));
    $to_date = new \DateTime($settings->get('date_checkins_end'));

    $to = $to_date->format('m/d/Y g:i A');
    $number_weeks = ceil($from_date->diff($to_date)->days / 7);
    if ($to_date > $current_date) {
      $to = $current_date->format('m/d/Y g:i A');
      $number_weeks = ceil($from_date->diff($current_date)->days / 7);
    }
    $from = $from_date->format('m/d/Y g:i A');
    $past_result = PersonifyApi::getPersonifyVisitCountByDate($membership_id, $from, $to);

    // Get first visit date.
    try {
      $first_visit = new \DateTime($past_result->FirstVisitDate);
    }
    catch (\Exception $e) {
      $first_visit = $from;
    }
    // If user registered after From date, then recalculate number of weeks.
    if ($first_visit > $from_date) {
      $number_weeks = ceil($first_visit->diff($current_date)->days / 7);
    }

    // Calculate a goal for a member.
    $goal = (int) $settings->get('new_member_goal_number');
    if (empty($past_result->ErrorMessage) && $past_result->TotalVisits > 0) {
      $limit_goal = $settings->get('limit_goal_number');
      $calculated_goal = ceil((($past_result->TotalVisits / $number_weeks) * 2) + 1);
      $goal = min(max($goal, $calculated_goal), $limit_goal);
    }
    // Visit goal for late members.
    $close_date = new \DateTime($settings->get('date_campaign_close'));
    $count_days = $current_date->diff($close_date)->days;
    // Set 1 if current date is a date when campaign will be closed.
    $count_days = max(1, $count_days);
    $goal = min($goal, $count_days);

    return $goal;
  }

  /**
   * Obfuscate email address.
   *
   * @param string $email
   *   Email address to obfuscate.
   *
   * @return string
   *   Obfuscated email address.
   */
  protected function obfuscateEmail($email) {
    return preg_replace('/(?<=.{2}).(?=.+@)/u', '*', $email);
  }

  /**
   * Refresh values.
   */
  protected function refreshValues(FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    unset($user_input['membership_id']);
    $form_state->setUserInput($user_input);
    $form_state->set('membership_id', NULL);
    $form_state->set('personify_member', NULL);
    $form_state->set('personify_email', NULL);
  }

}
