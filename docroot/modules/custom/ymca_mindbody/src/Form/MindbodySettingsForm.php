<?php

namespace Drupal\ymca_mindbody\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;

/**
 * Provides MindBody settings form.
 */
class MindbodySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ymca_mindbody_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ymca_mindbody.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ymca_mindbody.settings');

    $form['disabled_form_block_id'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Disabled form Block ID'),
      '#default_value' => !empty($config->get('disabled_form_block_id')) ? $config->get('disabled_form_block_id') : '',
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('ymca_mindbody.settings')
      ->set('disabled_form_block_id', $values['disabled_form_block_id'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
