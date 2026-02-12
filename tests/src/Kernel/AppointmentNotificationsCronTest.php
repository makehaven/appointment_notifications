<?php

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Regression tests for appointment_notifications cron behavior.
 *
 * @group appointment_notifications
 */
class AppointmentNotificationsCronTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'options',
    'datetime',
    'profile',
    'appointment_notifications',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('profile');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_notifications']);

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->createAppointmentFields();
  }

  /**
   * Ensures reminder processing still runs when feedback candidates are empty.
   */
  public function testCronProcessesRemindersWithoutFeedbackCandidates(): void {
    $site_tz = \Drupal::config('system.date')->get('timezone.default') ?: 'UTC';
    $tomorrow = (new \DateTimeImmutable('now', new \DateTimeZone($site_tz)))
      ->modify('+1 day')
      ->format('Y-m-d');

    $member = User::create([
      'name' => 'member_reminder_test',
      'mail' => 'member-reminder@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'host_reminder_test',
      'mail' => 'host-reminder@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Reminder Path Regression',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => $tomorrow],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_purpose' => 'project',
      'field_appointment_feedback' => '',
      'field_appointment_result' => '',
      'field_appointment_note' => '',
    ]);
    $appointment->save();

    $config = \Drupal::configFactory()->getEditable('appointment_notifications.settings');
    $config
      ->set('development_mode', TRUE)
      ->set('email_logging', FALSE)
      ->set('reminder_enabled', TRUE)
      ->set('reminder_days_before', 1)
      ->set('calendar_invites_enabled', FALSE)
      ->save();

    \Drupal::state()->set('appointment_notifications.sent.member_reminder', []);
    \Drupal::state()->set('appointment_notifications.sent.host_reminder', []);
    \Drupal::state()->set('appointment_notifications.sent.feedback', []);

    appointment_notifications_cron();

    $member_sent = \Drupal::state()->get('appointment_notifications.sent.member_reminder', []);
    $host_sent = \Drupal::state()->get('appointment_notifications.sent.host_reminder', []);

    $this->assertArrayHasKey($appointment->id(), $member_sent);
    $this->assertArrayHasKey($appointment->id(), $host_sent);
    $this->assertSame($tomorrow, $member_sent[$appointment->id()]);
    $this->assertSame($tomorrow, $host_sent[$appointment->id()]);
  }

  /**
   * Creates required appointment fields used by notification logic.
   */
  protected function createAppointmentFields(): void {
    $this->ensureField('field_appointment_status', 'list_string', [
      'allowed_values' => [
        'scheduled' => 'scheduled',
        'canceled' => 'canceled',
      ],
    ]);
    $this->attachField('field_appointment_status', 'Appointment status');

    $this->ensureField('field_appointment_date', 'datetime', ['datetime_type' => 'date']);
    $this->attachField('field_appointment_date', 'Appointment date');

    $this->ensureField('field_appointment_host', 'entity_reference', ['target_type' => 'user']);
    $this->attachField('field_appointment_host', 'Appointment host', ['handler' => 'default']);

    $this->ensureField('field_appointment_purpose', 'string', ['max_length' => 255]);
    $this->attachField('field_appointment_purpose', 'Appointment purpose');

    $this->ensureField('field_appointment_feedback', 'string_long', []);
    $this->attachField('field_appointment_feedback', 'Appointment feedback');

    $this->ensureField('field_appointment_result', 'list_string', [
      'allowed_values' => [
        'met_successful' => 'Success',
        'met_unsuccesful' => 'Problems',
        'member_absent' => 'Member absent',
        'volunteer_absent' => 'Volunteer absent',
      ],
    ]);
    $this->attachField('field_appointment_result', 'Appointment result');

    $this->ensureField('field_appointment_note', 'string_long', []);
    $this->attachField('field_appointment_note', 'Appointment note');
  }

  /**
   * Ensures a field storage exists for appointment nodes.
   */
  protected function ensureField(string $field_name, string $type, array $settings): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => 1,
      ])->save();
    }
  }

  /**
   * Attaches a field to the appointment bundle if needed.
   */
  protected function attachField(string $field_name, string $label, array $settings = []): void {
    if (!FieldConfig::loadByName('node', 'appointment', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => $label,
        'settings' => $settings,
      ])->save();
    }
  }

}
