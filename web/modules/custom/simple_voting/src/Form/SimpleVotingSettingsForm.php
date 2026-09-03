<?php

declare(strict_types=1);

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Global settings for the simple voting system.
 *
 * Exposes the single "kill switch" required by the case: an administrator
 * must be able to disable voting entirely, blocking the flow both in the
 * CMS and for the external API. It also exposes the API key used to
 * authenticate external applications.
 */
class SimpleVotingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_voting.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_voting_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('simple_voting.settings');

    $form['voting_disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable voting globally'),
      '#description' => $this->t('When enabled, no votes can be cast anywhere: not in the CMS, not through the external API. Existing questions and results remain visible according to their own settings.'),
      '#default_value' => $config->get('voting_disabled'),
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('External API key'),
      '#description' => $this->t('Shared secret external applications must send in the "X-API-Key" header to use the voting REST API. Leave empty to block all external API access.'),
      '#default_value' => $config->get('api_key'),
      '#size' => 60,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('simple_voting.settings')
      ->set('voting_disabled', (bool) $form_state->getValue('voting_disabled'))
      ->set('api_key', trim((string) $form_state->getValue('api_key')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
