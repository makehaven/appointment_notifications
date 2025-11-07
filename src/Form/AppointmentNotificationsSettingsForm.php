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

    $form['replacement_patterns'] = [
      '#type' => 'details',
      '#title' => $this->t('Available Replacement Patterns'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => '<table>
        <tr><th>Pattern</th><th>Description</th></tr>
        <tr><td>@title</td><td>The title of the appointment.</td></tr>
        <tr><td>@date</td><td>The date of the appointment.</td></tr>
        <tr><td>@time</td><td>The time of the appointment.</td></tr>
        <tr><td>@purpose</td><td>The purpose of the appointment.</td></tr>
        <tr><td>@feedback</td><td>The feedback provided for the appointment.</td></tr>
        <tr><td>@result</td><td>The result of the appointment.</td></tr>
        <tr><td>@link</td><td>A link to the appointment.</td></tr>
        <tr><td>@feedback_link</td><td>A link to the feedback form.</td></tr>
        <tr><td>@scheduled_by</td><td>The name of the user who scheduled the appointment.</td></tr>
        <tr><td>@badges</td><td>The badges associated with the appointment.</td></tr>
        <tr><td>@note</td><td>The note for the appointment.</td></tr>
        <tr><td>@volunteer_name</td><td>The name of the host volunteer.</td></tr>
        <tr><td>@recipient_name</td><td>The name of the recipient.</td></tr>
        <tr><td>@member_email</td><td>The email of the member.</td></tr>
        <tr><td>@member_slack_id</td><td>The Slack ID of the member.</td></tr>
        <tr><td>@member_slack_link</td><td>A link to the member\'s Slack profile.</td></tr>
        <tr><td>@host_email</td><td>The email of the host.</td></tr>
        <tr><td>@host_slack_id</td><td>The Slack ID of the host.</td></tr>
        <tr><td>@host_slack_link</td><td>A link to the host\'s Slack profile.</td></tr>
        <tr><td>@author_name</td><td>The name of the author of the node.</td></tr>
      </table>',
    ];

    $form['email_sender'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender Email Address'),
      '#default_value' => $config->get('email_sender'),
      '#description' => $this->t('The email address from which notifications will be sent.'),
    ];

    $form['problem_notice'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Problem Notice Email'),
      '#tree' => TRUE, 
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This email is sent to staff when an issue is reported during an appointment. It is triggered when a user submits the appointment feedback form with a "problem" status.'),
    ];

    $form['problem_notice']['staff_email'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Staff Notification Emails'),
      '#default_value' => $config->get('staff_email'),
      '#description' => $this->t('Email addresses (comma-separated) where notifications about problems will be sent. This is the "To" address for this notification.'),
    ];

    $form['problem_notice']['email_subject_problem_notice'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Problem Notice Email'),
      '#default_value' => $config->get('email_subject_problem_notice'),
    ];

    $form['problem_notice']['email_body_problem_notice'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Problem Notice Email'),
      '#default_value' => $config->get('email_body_problem_notice'),
      '#description' => $this->t('Use placeholders like @title, @date, @result, @feedback, @author_name, @volunteer_name, @link, etc.'),
    ];

    $form['member_scheduled'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Member Scheduled Email'),
      '#tree' => TRUE, 
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This email is sent to the member who scheduled the appointment. It is triggered immediately after an appointment is successfully scheduled. The "From" address is the Sender Email Address and the "To" address is the member\'s email.'),
    ];

    $form['member_scheduled']['email_subject_member_scheduled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Member Scheduled Email'),
      '#default_value' => $config->get('email_subject_member_scheduled'),
    ];

    $form['member_scheduled']['email_body_member_scheduled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Member Scheduled Email'),
      '#default_value' => $config->get('email_body_member_scheduled'),
      '#description' => $this->t('Use placeholders like @title, @date, @with, etc.'),
    ];

    $form['host_scheduled'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Host Scheduled Email'),
      '#tree' => TRUE, 
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This email is sent to the host of the appointment. It is triggered at the same time as the member scheduled email. The "From" address is the Sender Email Address and the "To" address is the host\'s email.'),
    ];

    $form['host_scheduled']['email_subject_host_scheduled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Host Scheduled Email'),
      '#default_value' => $config->get('email_subject_host_scheduled'),
    ];

    $form['host_scheduled']['email_body_host_scheduled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Host Scheduled Email'),
      '#default_value' => $config->get('email_body_host_scheduled'),
      '#description' => $this->t('Use placeholders like @title, @date, @with, etc.'),
    ];

    $form['canceled'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Canceled Appointment Email'),
      '#tree' => TRUE, 
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This email is sent to both the member and the host when an appointment is canceled. The "From" address is the Sender Email Address and the "To" address is the member\'s and host\'s email.'),
    ];

    $form['canceled']['email_subject_canceled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Canceled Appointment Email'),
      '#default_value' => $config->get('email_subject_canceled'),
    ];

    $form['canceled']['email_body_canceled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Canceled Appointment Email'),
      '#default_value' => $config->get('email_body_canceled'),
      '#description' => $this->t('Use placeholders like @title, @date, @with, etc.'),
    ];

    $form['feedback_invitation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Feedback Invitation Email'),
      '#tree' => TRUE, 
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This email is sent to the member after an appointment is completed to ask for feedback. The "From" address is the Sender Email Address and the "To" address is the member\'s email.'),
    ];

    $form['feedback_invitation']['email_subject_feedback_invitation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Feedback Invitation Email'),
      '#default_value' => $config->get('email_subject_feedback_invitation'),
    ];

    $form['feedback_invitation']['email_body_feedback_invitation'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Feedback Invitation Email'),
      '#default_value' => $config->get('email_body_feedback_invitation'),
      '#description' => $this->t('Use placeholders like @title, @date, @link, @feedback_link, etc.'),
    ];

    $form['reminder'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Reminder Emails'),
      '#tree' => TRUE,
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('Configure optional reminder emails that are sent before the appointment date.'),
    ];

    $form['reminder']['reminder_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable reminder emails'),
      '#default_value' => $config->get('reminder_enabled'),
      '#description' => $this->t('When enabled, reminder emails are sent to the member and host before the appointment.'),
    ];

    $form['reminder']['reminder_days_before'] = [
      '#type' => 'number',
      '#title' => $this->t('Days before appointment'),
      '#default_value' => $config->get('reminder_days_before') ?? 1,
      '#min' => 1,
      '#description' => $this->t('Reminders are sent this many days before the appointment date.'),
      '#states' => [
        'disabled' => [
          ':input[name="reminder[reminder_enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['reminder']['email_subject_member_reminder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Member Reminder Email'),
      '#default_value' => $config->get('email_subject_member_reminder'),
      '#states' => [
        'disabled' => [
          ':input[name="reminder[reminder_enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['reminder']['email_body_member_reminder'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Member Reminder Email'),
      '#default_value' => $config->get('email_body_member_reminder'),
      '#description' => $this->t('Use placeholders like @recipient_name, @title, @date, @time, @link, etc.'),
      '#states' => [
        'disabled' => [
          ':input[name="reminder[reminder_enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['reminder']['email_subject_host_reminder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject for Host Reminder Email'),
      '#default_value' => $config->get('email_subject_host_reminder'),
      '#states' => [
        'disabled' => [
          ':input[name="reminder[reminder_enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['reminder']['email_body_host_reminder'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body for Host Reminder Email'),
      '#default_value' => $config->get('email_body_host_reminder'),
      '#description' => $this->t('Use placeholders like @recipient_name, @scheduled_by, @date, @time, @member_email, @link, etc.'),
      '#states' => [
        'disabled' => [
          ':input[name="reminder[reminder_enabled]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['calendar_invites'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Calendar Invitations'),
      '#tree' => TRUE,
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('Attach iCalendar (`.ics`) files to scheduling and cancellation emails so recipients can add or remove the appointment in their calendars.'),
    ];

    $form['calendar_invites']['calendar_invites_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable calendar invitations'),
      '#default_value' => $config->get('calendar_invites_enabled'),
      '#description' => $this->t('When enabled, `.ics` files are attached to initial scheduling emails and their cancellations.'),
    ];

    $form['email_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Email Logging'),
      '#default_value' => $config->get('email_logging'),
      '#description' => $this->t('If enabled, all outgoing emails will be logged. This is independent of development mode.'),
    ];

    $form['development_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Development Mode'),
      '#default_value' => $config->get('development_mode'),
      '#description' => $this->t('If enabled, emails will not be sent.'),
    ];

    $form['contribute'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contribute'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This module is open source. If you have suggestions for improvements or bug fixes, please <a href="https://github.com/makehaven/appointment_notifications">contribute on GitHub</a>.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
  
    $this->config('appointment_notifications.settings')
      ->set('email_sender', $form_state->getValue('email_sender'))
      ->set('staff_email', $form_state->getValue(['problem_notice', 'staff_email']))
      ->set('email_subject_problem_notice', $form_state->getValue(['problem_notice', 'email_subject_problem_notice']))
      ->set('email_body_problem_notice', $form_state->getValue(['problem_notice', 'email_body_problem_notice']))
      ->set('email_subject_member_scheduled', $form_state->getValue(['member_scheduled', 'email_subject_member_scheduled']))
      ->set('email_body_member_scheduled', $form_state->getValue(['member_scheduled', 'email_body_member_scheduled']))
      ->set('email_subject_host_scheduled', $form_state->getValue(['host_scheduled', 'email_subject_host_scheduled']))
      ->set('email_body_host_scheduled', $form_state->getValue(['host_scheduled', 'email_body_host_scheduled']))
      ->set('email_subject_canceled', $form_state->getValue(['canceled', 'email_subject_canceled']))
      ->set('email_body_canceled', $form_state->getValue(['canceled', 'email_body_canceled']))
      ->set('email_subject_feedback_invitation', $form_state->getValue(['feedback_invitation', 'email_subject_feedback_invitation']))
      ->set('email_body_feedback_invitation', $form_state->getValue(['feedback_invitation', 'email_body_feedback_invitation']))
      ->set('reminder_enabled', $form_state->getValue(['reminder', 'reminder_enabled']))
      ->set('reminder_days_before', (int) $form_state->getValue(['reminder', 'reminder_days_before']))
      ->set('email_subject_member_reminder', $form_state->getValue(['reminder', 'email_subject_member_reminder']))
      ->set('email_body_member_reminder', $form_state->getValue(['reminder', 'email_body_member_reminder']))
      ->set('email_subject_host_reminder', $form_state->getValue(['reminder', 'email_subject_host_reminder']))
      ->set('email_body_host_reminder', $form_state->getValue(['reminder', 'email_body_host_reminder']))
      ->set('calendar_invites_enabled', $form_state->getValue(['calendar_invites', 'calendar_invites_enabled']))
      ->set('email_logging', $form_state->getValue('email_logging'))
      ->set('development_mode', $form_state->getValue('development_mode'))
      ->save();
  }
  
}
