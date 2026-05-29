<?php

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests booker-cancel attendee promotion.
 *
 * When the original booker cancels but a member has joined, ownership transfers
 * to the joined attendee and the session stays active instead of being killed.
 *
 * @group appointment_notifications
 */
class AppointmentRebookOnBookerCancelTest extends KernelTestBase {

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
   * Booker cancel with a joined attendee transfers ownership, session stays on.
   */
  public function testPromotionTransfersOwnershipAndKeepsSessionActive(): void {
    \Drupal::configFactory()->getEditable('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)
      ->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', FALSE)
      ->save();

    [$booker, $host, $attendee] = $this->createUsers();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Pottery Kiln Badge',
      'uid' => $booker->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => '2026-06-01'],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_attendees' => [['target_id' => $attendee->id()]],
      'field_reservation_cancellation' => [],
      'field_appointment_note' => '',
    ]);
    $appointment->save();

    // Clear insert-time scheduled mails so we only assess the rebook send.
    $this->container->get('state')->set('system.test_mail_collector', []);

    $promoted = _appointment_notifications_promote_attendee_to_owner($appointment);

    $this->assertNotNull($promoted, 'An attendee was promoted.');
    $this->assertSame((int) $attendee->id(), (int) $promoted->id(), 'The joined attendee becomes the new owner.');

    $reloaded = Node::load($appointment->id());
    $this->assertSame((int) $attendee->id(), (int) $reloaded->getOwnerId(), 'Ownership transferred to the attendee.');
    $this->assertSame('scheduled', (string) $reloaded->get('field_appointment_status')->value, 'Session is not cancelled.');
    $cancel_values = array_column($reloaded->get('field_reservation_cancellation')->getValue(), 'value');
    $this->assertNotContains('Cancel', $cancel_values, 'Reservation is not flagged for cancellation.');
    $this->assertTrue($reloaded->get('field_appointment_attendees')->isEmpty(), 'Promoted attendee is removed from the attendees list.');
    $this->assertStringContainsString('Booking transferred', (string) $reloaded->get('field_appointment_note')->value, 'Transfer is logged on the note.');

    $emails = $this->container->get('state')->get('system.test_mail_collector', []);
    $recipients = array_map(static fn(array $mail): string => (string) ($mail['to'] ?? ''), $emails);
    sort($recipients);
    $this->assertSame([
      'rebook-attendee@example.com',
      'rebook-host@example.com',
    ], $recipients, 'Promoted member and host are notified; the cancelling booker is not.');
  }

  /**
   * A second joined attendee remains on the session after promotion.
   */
  public function testRemainingAttendeesArePreserved(): void {
    [$booker, $host, $attendee] = $this->createUsers();
    $attendee2 = User::create([
      'name' => 'rebook_attendee2',
      'mail' => 'rebook-attendee2@example.com',
      'status' => 1,
    ]);
    $attendee2->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Pottery Kiln Badge',
      'uid' => $booker->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => '2026-06-02'],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_attendees' => [
        ['target_id' => $attendee->id()],
        ['target_id' => $attendee2->id()],
      ],
      'field_reservation_cancellation' => [],
      'field_appointment_note' => '',
    ]);
    $appointment->save();

    $promoted = _appointment_notifications_promote_attendee_to_owner($appointment);
    $this->assertSame((int) $attendee->id(), (int) $promoted->id(), 'Longest-waiting attendee is promoted first.');

    $reloaded = Node::load($appointment->id());
    $remaining = array_column($reloaded->get('field_appointment_attendees')->getValue(), 'target_id');
    $this->assertSame([(string) $attendee2->id()], array_map('strval', $remaining), 'The second attendee stays on the session.');
  }

  /**
   * With no attendees, promotion declines so the caller falls back to cancel.
   */
  public function testNoAttendeesReturnsNullForNormalCancellation(): void {
    [$booker, $host] = $this->createUsers();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Solo Booking',
      'uid' => $booker->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => '2026-06-03'],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_appointment_attendees' => [],
      'field_reservation_cancellation' => [],
      'field_appointment_note' => '',
    ]);
    $appointment->save();

    $this->assertNull(
      _appointment_notifications_promote_attendee_to_owner($appointment),
      'No promotion happens without attendees.'
    );

    // The caller falls back to a normal cancellation.
    $this->assertTrue(_appointment_notifications_cancel_appointment($appointment));
    $reloaded = Node::load($appointment->id());
    $this->assertSame('canceled', (string) $reloaded->get('field_appointment_status')->value);
    $this->assertSame((int) $booker->id(), (int) $reloaded->getOwnerId(), 'Owner is unchanged when no promotion occurs.');
  }

  /**
   * Creates booker, host, and attendee users.
   */
  protected function createUsers(): array {
    $booker = User::create([
      'name' => 'rebook_booker',
      'mail' => 'rebook-booker@example.com',
      'status' => 1,
    ]);
    $booker->save();

    $host = User::create([
      'name' => 'rebook_host',
      'mail' => 'rebook-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $attendee = User::create([
      'name' => 'rebook_attendee',
      'mail' => 'rebook-attendee@example.com',
      'status' => 1,
    ]);
    $attendee->save();

    return [$booker, $host, $attendee];
  }

  /**
   * Creates fields used by the rebook flow.
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

    // Unlimited cardinality matches the live attendees field.
    $this->ensureField('field_appointment_attendees', 'entity_reference', ['target_type' => 'user'], -1);
    $this->attachField('field_appointment_attendees', 'Appointment attendees', ['handler' => 'default']);

    $this->ensureField('field_appointment_note', 'string_long', []);
    $this->attachField('field_appointment_note', 'Appointment note');

    // Touched by the scheduled-email path that fires on node insert.
    $this->ensureField('field_appointment_purpose', 'string', ['max_length' => 255]);
    $this->attachField('field_appointment_purpose', 'Appointment purpose');

    $this->ensureField('field_appointment_feedback', 'string_long', []);
    $this->attachField('field_appointment_feedback', 'Appointment feedback');

    $this->ensureField('field_appointment_result', 'list_string', [
      'allowed_values' => [
        'met_successful' => 'Success',
        'met_unsuccesful' => 'Problems',
      ],
    ]);
    $this->attachField('field_appointment_result', 'Appointment result');

    $this->ensureField('field_reservation_cancellation', 'list_string', [
      'allowed_values' => [
        'Cancel' => 'Cancel this reservation',
      ],
    ]);
    $this->attachField('field_reservation_cancellation', 'Reservation cancellation');
  }

  /**
   * Ensures a field storage exists for appointment nodes.
   */
  protected function ensureField(string $field_name, string $type, array $settings, int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
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
