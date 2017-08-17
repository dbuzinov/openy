<?php

namespace Drupal\openy_digital_signage_classes_schedule\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class OpenYClassesSessionSettingsForm.
 *
 * @package Drupal\openy_digital_signage_classes_schedule\Form
 *
 * @ingroup openy_digital_signage_classes_schedule
 */
class OpenYClassesSessionSettingsForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'openy_ds_classes_session_settings';
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Empty implementation of the abstract submit class.
  }

  /**
   * Defines the settings form for OpenY Digital Signage Screen entities.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Form definition array.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['openy_ds_classes_session_settings']['#markup'] = $this->t('Settings form for Digital Signage Classes Session entities. Manage field settings here.');
    return $form;
  }

}
