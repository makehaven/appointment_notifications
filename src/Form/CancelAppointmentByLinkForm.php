<?php

namespace Drupal\appointment_notifications\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Confirmation form for secure appointment cancellation links.
 */
final class CancelAppointmentByLinkForm extends ConfirmFormBase {

  /**
   * The appointment being canceled.
   */
  protected ?NodeInterface $appointment = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_notifications_cancel_by_link_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Cancel this appointment?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    if (!$this->appointment) {
      return $this->t('This appointment could not be found.');
    }

    $details = _appointment_notifications_get_schedule_details($this->appointment);
    return $this->t(
      'This will cancel "@title" on @date at @time and notify both member and facilitator.',
      [
        '@title' => $this->appointment->getTitle(),
        '@date' => $details['date'] ?? $this->t('Unknown date'),
        '@time' => $details['time'] ?? $this->t('Unknown time'),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Cancel appointment');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    if ($this->appointment instanceof NodeInterface) {
      return $this->appointment->toUrl();
    }
    return Url::fromUserInput('/appointments');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?NodeInterface $node = NULL,
    string $audience = '',
    int $expires = 0,
    string $token = ''
  ): array {
    $this->appointment = $node;
    $form = parent::buildForm($form, $form_state);

    if ($this->appointment instanceof NodeInterface) {
      $status = $this->appointment->hasField('field_appointment_status')
        ? (string) $this->appointment->get('field_appointment_status')->value
        : '';
      if ($status === 'canceled') {
        $this->messenger()->addStatus($this->t('This appointment is already canceled.'));
        $form['actions']['submit']['#access'] = FALSE;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->appointment instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Unable to cancel the appointment.'));
      $form_state->setRedirectUrl(Url::fromUserInput('/appointments'));
      return;
    }

    if (_appointment_notifications_cancel_appointment($this->appointment)) {
      $this->messenger()->addStatus($this->t('Appointment canceled. Notifications were sent to both parties.'));
    }
    else {
      $this->messenger()->addStatus($this->t('This appointment was already canceled.'));
    }

    $form_state->setRedirectUrl($this->appointment->toUrl());
  }

}
