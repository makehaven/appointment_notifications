<?php

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
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
   * Checks start-time boundaries, suppression, fallback and one-time delivery.
   */
  public function testFeedbackAtStart(): void {
    $now = strtotime('2026-09-10 19:00:00 UTC');
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn($now);
    $clock->method('getCurrentTime')->willReturnCallback(static function () use (&$now) {
      return $now;
    });
    $this->container->set('datetime.time', $clock);
    \Drupal::configFactory()->getEditable('system.date')->set('timezone.default', 'America/New_York')->save();
    \Drupal::configFactory()->getEditable('system.mail')->set('interface.default', 'test_mail_collector')->save();
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', TRUE)->set('email_logging', FALSE)
      ->set('reminder_enabled', FALSE)->set('calendar_invites_enabled', FALSE)->save();

    $this->ensureField('field_host_start_time', 'string', ['max_length' => 255]);
    $this->attachField('field_host_start_time', 'Host start');
    $this->ensureField('field_appointment_slot', 'string', ['max_length' => 255]);
    $this->attachField('field_appointment_slot', 'Slot');

    $due = $this->feedbackAppointment($now + 3600);
    $later = $this->feedbackAppointment($now + 3601);
    $future = $this->feedbackAppointment($now + 7200);
    // A stale legacy date must not send a future timerange appointment early.
    $future->set('field_appointment_date', '2026-09-09')->save();
    $this->feedbackAppointment($now - 1800, ['field_appointment_status' => 'canceled']);
    $this->feedbackAppointment($now - 1800, ['status' => 0]);
    $this->feedbackAppointment($now - 1800, ['field_appointment_feedback' => 'Already answered']);
    $outcome = $this->feedbackAppointment($now - 1800, ['field_appointment_result' => 'met_successful']);
    $legacy = $this->feedbackAppointment(NULL, ['field_appointment_date' => '2026-09-09']);
    // Host/slot timing wins over a broad stored shift window.
    $slot = $this->feedbackAppointment($now + 3600, [
      'field_appointment_date' => '2026-09-10',
      'field_host_start_time' => '2026-09-10 15:00:00',
      'field_appointment_slot' => '1',
    ]);

    $this->feedbackAppointment(NULL, ['field_appointment_date' => '2026-09-10']);
    $this->feedbackAppointment($now - 4 * 86400);
    $this->feedbackAppointment($now - 2 * 86400);
    $old_sent = $this->feedbackAppointment($now - 1800);
    \Drupal::state()->set('appointment_notifications.sent.feedback', [$old_sent->id() => '2026-09-09']);
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')->set('development_mode', FALSE)->save();

    appointment_notifications_cron();
    $messages = \Drupal::state()->get('system.test_mail_collector', []);
    $this->assertCount(4, $messages);
    $this->assertSame('appointment_notifications_appointment_feedback_invitation', $messages[0]['id']);
    $due_messages = array_values(array_filter($messages, static fn(array $message): bool => $message['to'] === $due->getOwner()->getEmail()));
    $this->assertCount(1, $due_messages);
    $this->assertStringContainsString('/appointment/' . $due->id() . '/feedback', $due_messages[0]['body']);
    $this->assertStringContainsString('When you have finished', $due_messages[0]['body']);
    $sent = \Drupal::state()->get('appointment_notifications.sent.feedback');
    foreach ([$due, $outcome, $legacy, $old_sent, $slot] as $node) {
      $this->assertArrayHasKey($node->id(), $sent);
    }
    $this->assertArrayNotHasKey($later->id(), $sent);
    $this->assertArrayNotHasKey($future->id(), $sent);
    appointment_notifications_cron();
    $this->assertCount(4, \Drupal::state()->get('system.test_mail_collector'));
    $now++;
    appointment_notifications_cron();
    $this->assertCount(5, \Drupal::state()->get('system.test_mail_collector'));
    $this->assertArrayHasKey($later->id(), \Drupal::state()->get('appointment_notifications.sent.feedback'));
    // Moving the session forward must postpone its pending invitation.
    $future->set('field_appointment_timerange', ['value' => $now + 7200, 'end_value' => $now + 10800, 'duration' => 60])->save();
    $now += 3600;
    appointment_notifications_cron();
    $this->assertCount(5, \Drupal::state()->get('system.test_mail_collector'));
    $this->assertArrayNotHasKey($future->id(), \Drupal::state()->get('appointment_notifications.sent.feedback'));
  }

  /**
   * Failed mail can catch up after a missed day, but not beyond three days.
   */
  public function testFeedbackRetryAndLock(): void {
    $now = strtotime('2026-09-10 19:00:00 UTC');
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn($now);
    $clock->method('getCurrentTime')->willReturnCallback(static function () use (&$now) {
      return $now;
    });
    $this->container->set('datetime.time', $clock);
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', TRUE)->set('email_logging', FALSE)
      ->set('reminder_enabled', FALSE)->set('calendar_invites_enabled', FALSE)->save();
    $node = $this->feedbackAppointment($now - 1800);
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')->set('development_mode', FALSE)->save();
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->exactly(2))->method('mail')->willReturnOnConsecutiveCalls(['result' => FALSE], ['result' => TRUE]);
    $this->container->set('plugin.manager.mail', $mail);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $original_lock = $this->container->get('lock');
    $this->container->set('lock', $lock);
    _appointment_notifications_process_feedback();
    $this->assertNull(\Drupal::state()->get('appointment_notifications.feedback_since'));
    $this->container->set('lock', $original_lock);
    _appointment_notifications_process_feedback();
    $this->assertArrayNotHasKey($node->id(), \Drupal::state()->get('appointment_notifications.sent.feedback', []));
    $now += 2 * 86400;
    _appointment_notifications_process_feedback();
    $this->assertArrayHasKey($node->id(), \Drupal::state()->get('appointment_notifications.sent.feedback'));
    // Even without a success marker, an expired appointment is no longer due.
    \Drupal::state()->set('appointment_notifications.sent.feedback', []);
    $now += 2 * 86400;
    _appointment_notifications_process_feedback();
    $this->assertSame([], \Drupal::state()->get('appointment_notifications.sent.feedback'));
  }

  /**
   * Creates an appointment without sending fixture notifications.
   */
  protected function feedbackAppointment(?int $end, array $overrides = []): Node {
    $member = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $member->save();
    $values = [
      'type' => 'appointment',
      'title' => 'Feedback timing',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_host' => $member->id(),
      'field_appointment_purpose' => 'project',
    ];
    if ($end !== NULL) {
      $values['field_appointment_timerange'] = ['value' => $end - 3600, 'end_value' => $end, 'duration' => 60];
    }
    $node = Node::create(array_replace($values, $overrides));
    $node->save();
    return $node;
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
