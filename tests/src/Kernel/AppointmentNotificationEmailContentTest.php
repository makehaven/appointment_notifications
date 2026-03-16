<?php

declare(strict_types=1);

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Verifies appointment emails render the correct local time and ICS data.
 *
 * @group appointment_notifications
 */
class AppointmentNotificationEmailContentTest extends KernelTestBase {

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
    'smart_date',
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

    \Drupal::configFactory()->getEditable('system.date')
      ->set('timezone.default', 'America/New_York')
      ->save();

    \Drupal::configFactory()->getEditable('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    if (!NodeType::load('appointment')) {
      NodeType::create([
        'type' => 'appointment',
        'name' => 'Appointment',
      ])->save();
    }

    $this->createAppointmentFields();
  }

  /**
   * Ensures the member scheduled email includes local time and ICS UTC.
   */
  public function testMemberScheduledEmailContainsLocalTimeAndCalendarAttachment(): void {
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)
      ->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', TRUE)
      ->save();

    $member = User::create([
      'name' => 'member_time_test',
      'mail' => 'member-time@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'host_time_test',
      'mail' => 'host-time@example.com',
      'status' => 1,
    ]);
    $host->save();

    $timezone = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-03-20 14:00:00', $timezone);
    $end = $start->modify('+60 minutes');

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Member Email Time Test',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => $start->format('Y-m-d')],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_purpose' => 'project',
      'field_appointment_feedback' => '',
      'field_appointment_result' => 'met_successful',
      'field_appointment_note' => '',
      'field_appointment_slot' => [['value' => '1']],
      'field_appointment_timerange' => [[
        'value' => $start->getTimestamp(),
        'end_value' => $end->getTimestamp(),
        'timezone' => 'America/New_York',
        'duration' => 60,
      ]],
    ]);
    $appointment->save();

    $this->container->get('state')->set('system.test_mail_collector', []);

    _appointment_notifications_send_email($appointment, 'member_scheduled');

    $emails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $emails);

    $email = $emails[0];
    $this->assertSame('member-time@example.com', $email['to']);
    $this->assertStringContainsString('Appointment Scheduled: Member Email Time Test at 2:00 PM', (string) $email['subject']);
    $this->assertStringContainsString('**Date:** Friday, March 20, 2026 at 2:00 PM', (string) $email['body']);

    $attachments = $email['params']['attachments'] ?? [];
    $this->assertCount(1, $attachments, 'Calendar invite should be attached.');
    $this->assertStringContainsString('DTSTART:20260320T180000Z', $attachments[0]['filecontent']);
    $this->assertStringContainsString('DTEND:20260320T190000Z', $attachments[0]['filecontent']);
  }

  /**
   * Ensures canceled emails still tell recipients the canceled time.
   */
  public function testCanceledEmailContainsCanceledTime(): void {
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)
      ->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', FALSE)
      ->save();

    $member = User::create([
      'name' => 'member_cancel_time_test',
      'mail' => 'member-cancel-time@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'host_cancel_time_test',
      'mail' => 'host-cancel-time@example.com',
      'status' => 1,
    ]);
    $host->save();

    $timezone = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-11-10 09:30:00', $timezone);
    $end = $start->modify('+60 minutes');

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Canceled Email Time Test',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => $start->format('Y-m-d')],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_purpose' => 'project',
      'field_appointment_feedback' => '',
      'field_appointment_result' => 'met_successful',
      'field_appointment_note' => '',
      'field_appointment_timerange' => [[
        'value' => $start->getTimestamp(),
        'end_value' => $end->getTimestamp(),
        'timezone' => 'America/New_York',
        'duration' => 60,
      ]],
    ]);
    $appointment->save();

    $this->container->get('state')->set('system.test_mail_collector', []);

    _appointment_notifications_send_email($appointment, 'canceled');

    $emails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(2, $emails);

    foreach ($emails as $email) {
      $this->assertStringContainsString('scheduled for Tuesday, November 10, 2026 at 9:30 AM has been canceled', (string) $email['body']);
    }
  }

  /**
   * Ensures host scheduled emails include the rendered local time.
   */
  public function testHostScheduledEmailContainsLocalTime(): void {
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)
      ->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', FALSE)
      ->save();

    $member = User::create([
      'name' => 'member_host_email_test',
      'mail' => 'member-host-email@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'host_host_email_test',
      'mail' => 'host-host-email@example.com',
      'status' => 1,
    ]);
    $host->save();

    $timezone = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-03 18:30:00', $timezone);
    $end = $start->modify('+60 minutes');

    $appointment = $this->createAppointmentNode($member, $host, $start, $end, [
      'title' => 'Host Email Time Test',
      'field_appointment_note' => 'Bring project notes.',
    ]);

    $this->container->get('state')->set('system.test_mail_collector', []);
    _appointment_notifications_send_email($appointment, 'host_scheduled');

    $emails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $emails);
    $this->assertSame('host-host-email@example.com', $emails[0]['to']);
    $this->assertStringContainsString('Wednesday, June 3, 2026 at 6:30 PM', (string) $emails[0]['subject']);
    $this->assertStringContainsString('**Date:** Wednesday, June 3, 2026 at 6:30 PM.', (string) $emails[0]['body']);
  }

  /**
   * Ensures reminder emails generated by cron include the correct time.
   */
  public function testReminderEmailsIncludeAppointmentTime(): void {
    $site_tz = new \DateTimeZone('America/New_York');
    $now = new \DateTimeImmutable('now', $site_tz);
    $start = $now->modify('+1 day')->setTime(16, 30, 0);
    $end = $start->modify('+60 minutes');

    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)
      ->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', FALSE)
      ->set('reminder_enabled', TRUE)
      ->set('reminder_days_before', 1)
      ->set('email_subject_member_reminder', 'Reminder: "@title" on @date at @time')
      ->set('email_body_member_reminder', "Member reminder for @date at @time")
      ->set('email_subject_host_reminder', 'Reminder: Appointment with @scheduled_by on @date at @time')
      ->set('email_body_host_reminder', "Host reminder for @date at @time")
      ->save();

    $member = User::create([
      'name' => 'member_reminder_time_test',
      'mail' => 'member-reminder-time@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'host_reminder_time_test',
      'mail' => 'host-reminder-time@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = $this->createAppointmentNode($member, $host, $start, $end, [
      'title' => 'Reminder Time Test',
    ]);
    $appointment->save();

    \Drupal::state()->set('appointment_notifications.sent.member_reminder', []);
    \Drupal::state()->set('appointment_notifications.sent.host_reminder', []);
    $this->container->get('state')->set('system.test_mail_collector', []);

    appointment_notifications_cron();

    $emails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(2, $emails);

    $bodies = array_map(static fn(array $mail): string => (string) ($mail['body'] ?? ''), $emails);
    $subjects = array_map(static fn(array $mail): string => (string) ($mail['subject'] ?? ''), $emails);

    $this->assertContains('Member reminder for ' . $start->format('l, F j, Y') . ' at 4:30 PM', $bodies);
    $this->assertContains('Host reminder for ' . $start->format('l, F j, Y') . ' at 4:30 PM', $bodies);
    $this->assertContains('Reminder: "Reminder Time Test" on ' . $start->format('l, F j, Y') . ' at 4:30 PM', $subjects);
    $this->assertContains('Reminder: Appointment with member_reminder_time_test on ' . $start->format('l, F j, Y') . ' at 4:30 PM', $subjects);
  }

  /**
   * Creates an appointment node with sensible defaults for notification tests.
   */
  protected function createAppointmentNode(User $member, User $host, \DateTimeImmutable $start, \DateTimeImmutable $end, array $overrides = []): Node {
    $values = [
      'type' => 'appointment',
      'title' => 'Notification Time Test',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => $start->format('Y-m-d')],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_purpose' => 'project',
      'field_appointment_feedback' => '',
      'field_appointment_result' => 'met_successful',
      'field_appointment_note' => '',
      'field_appointment_slot' => [['value' => '1']],
      'field_appointment_timerange' => [[
        'value' => $start->getTimestamp(),
        'end_value' => $end->getTimestamp(),
        'timezone' => 'America/New_York',
        'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
      ]],
    ];

    foreach ($overrides as $key => $value) {
      $values[$key] = $value;
    }

    $appointment = Node::create($values);
    $appointment->save();
    return $appointment;
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

    $this->ensureField('field_appointment_timerange', 'smartdate', []);
    $this->attachField('field_appointment_timerange', 'Appointment time range');

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

    $this->ensureField('field_appointment_slot', 'list_string', [
      'allowed_values' => [
        '1' => '1',
        '1-5' => '1-5',
        '2' => '2',
        '2-5' => '2-5',
        '3' => '3',
        '3-5' => '3-5',
      ],
    ]);
    $this->attachField('field_appointment_slot', 'Appointment slot');
  }

  /**
   * Ensures a field storage exists for appointment nodes.
   */
  protected function ensureField(string $field_name, string $type, array $settings): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      $definition = [
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $field_name === 'field_appointment_slot' ? -1 : 1,
      ];
      FieldStorageConfig::create($definition)->save();
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
