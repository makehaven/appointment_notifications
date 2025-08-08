<?php

namespace Drupal\appointment_notifications\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class AppointmentNotificationsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['appointment_notifications.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_notifications_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('appointment_notifications.settings');

    $form['email_sender'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender Email Address'),
      '#default_value' => $config->get('email_sender'),
      '#description' => $this->t('The email address from which notifications will be sent.'),
    ];

    $form['staff_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Staff Notification Email'),
      '#default_value' => $config->get('staff_email'),
      '#description' => $this->t('Email address where notifications about problems will be sent.'),
    ];

    $form['email_subject_member_scheduled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Member Scheduled Email'),
      '#default_value' => $config->get('email_subject_member_scheduled'),
    ];

    $form['email_body_member_scheduled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Member Scheduled Email'),
      '#default_value' => $config->get('email_body_member_scheduled'),
      '#description' => $this->t('Use placeholders like {title}, {date}, {with}, etc.'),
    ];

    $form['email_subject_host_scheduled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Host Scheduled Email'),
      '#default_value' => $config->get('email_subject_host_scheduled'),
    ];

    $form['email_body_host_scheduled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Host Scheduled Email'),
      '#default_value' => $config->get('email_body_host_scheduled'),
      '#description' => $this->t('Use placeholders like {title}, {date}, {with}, etc.'),
    ];

    $form['email_subject_canceled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Canceled Appointment Email'),
      '#default_value' => $config->get('email_subject_canceled'),
    ];

    $form['email_body_canceled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Canceled Appointment Email'),
      '#default_value' => $config->get('email_body_canceled'),
      '#description' => $this->t('Use placeholders like {title}, {date}, {with}, etc.'),
    ];

    $form['development_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Development Mode'),
      '#default_value' => $config->get('development_mode'),
      '#description' => $this->t('If enabled, emails will not be sent and will be logged or displayed instead.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('appointment_notifications.settings')
      ->set('email_sender', $form_state->getValue('email_sender'))
      ->set('staff_email', $form_state->getValue('staff_email'))
      ->set('email_subject_member_scheduled', $form_state->getValue('email_subject_member_scheduled'))
      ->set('email_body_member_scheduled', $form_state->getValue('email_body_member_scheduled'))
      ->set('email_subject_host_scheduled', $form_state->getValue('email_subject_host_scheduled'))
      ->set('email_body_host_scheduled', $form_state->getValue('email_body_host_scheduled'))
      ->set('email_subject_canceled', $form_state->getValue('email_subject_canceled'))
      ->set('email_body_canceled', $form_state->getValue('email_body_canceled'))
      ->set('development_mode', $form_state->getValue('development_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
