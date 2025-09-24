<?php

namespace Drupal\did_ai_provider\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Configure Did API access.
 */
class DidAiSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'did_ai_provider.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'did_ai_provider_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Did Basic Auth Key'),
      '#description' => $this->t('Can be found and generated <a href="https://studio.d-id.com/account-settings" target="_blank">here</a>. Just paste as is, with colon in the middle.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->config(static::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

