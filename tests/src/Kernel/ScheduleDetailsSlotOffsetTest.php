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
 * Regression test: _appointment_notifications_get_schedule_details() does not
 * re-apply slot offsets to the already-slot-resolved timerange.
 *
 * Pre-fix bug: when slots were present and no host_start_time / start_time
 * URL override was supplied, the function fell back to using $timerange_start
 * as a slot base and added min(slot_offsets) minutes on top — shifting the
 * displayed time forward by 60+ minutes for slot 2 and beyond. Fired on
 * feedback emails, problem-notice emails, and Slack notifications.
 *
 * @group appointment_notifications
 */
class ScheduleDetailsSlotOffsetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
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

    if (!NodeType::load('appointment')) {
      NodeType::create(['type' => 'appointment', 'name' => 'Appointment'])->save();
    }

    $this->ensureField('field_appointment_timerange', 'smartdate', []);
    $this->attachField('field_appointment_timerange', 'Timerange');

    $this->ensureField('field_appointment_date', 'datetime', ['datetime_type' => 'date']);
    $this->attachField('field_appointment_date', 'Date');

    $this->ensureField('field_appointment_slot', 'list_string', [
      'allowed_values' => [
        '1' => '1', '1-5' => '1-5',
        '2' => '2', '2-5' => '2-5',
        '3' => '3', '3-5' => '3-5',
      ],
    ], -1);
    $this->attachField('field_appointment_slot', 'Slot');

    // Hook_insert in appointment_notifications.module touches these fields
    // even though the schedule-details function under test doesn't read them.
    $this->ensureField('field_appointment_status', 'list_string', [
      'allowed_values' => ['scheduled' => 'scheduled', 'canceled' => 'canceled'],
    ]);
    $this->attachField('field_appointment_status', 'Status');

    $this->ensureField('field_appointment_host', 'entity_reference', ['target_type' => 'user']);
    $this->attachField('field_appointment_host', 'Host');

    $this->ensureField('field_appointment_purpose', 'string', ['max_length' => 255]);
    $this->attachField('field_appointment_purpose', 'Purpose');

    $this->ensureField('field_appointment_feedback', 'string_long', []);
    $this->attachField('field_appointment_feedback', 'Feedback');

    $this->ensureField('field_appointment_result', 'list_string', [
      'allowed_values' => ['met_successful' => 'Success'],
    ]);
    $this->attachField('field_appointment_result', 'Result');

    $this->ensureField('field_appointment_note', 'string_long', []);
    $this->attachField('field_appointment_note', 'Note');

    \Drupal::moduleHandler()->loadInclude('appointment_notifications', 'module');
  }

  /**
   * Slot 2 (offset +60) — pre-fix this returned 9:00 PM (timerange + 60 again).
   */
  public function testSlot2DoesNotDoubleApplyOffset(): void {
    $tz = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-15 20:00:00', $tz);
    $end = $start->modify('+30 minutes');

    $node = $this->createAppointment($start, $end, ['2']);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertInstanceOf(\DateTimeInterface::class, $details['start_datetime']);
    $this->assertSame(
      $start->getTimestamp(),
      $details['start_datetime']->getTimestamp(),
      'start_datetime equals stored timerange (no double offset).'
    );
    $this->assertSame('8:00 PM', $details['time']);
    $this->assertSame('Monday, June 15, 2026', $details['date']);
  }

  /**
   * Slot 1 (offset 0) — would not have manifested the bug, but locks in
   * that timerange flows through unchanged.
   */
  public function testSlot1ReturnsTimerangeAsIs(): void {
    $tz = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-15 10:30:00', $tz);
    $end = $start->modify('+30 minutes');

    $node = $this->createAppointment($start, $end, ['1']);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertSame($start->getTimestamp(), $details['start_datetime']->getTimestamp());
    $this->assertSame('10:30 AM', $details['time']);
  }

  /**
   * Slot 2-5 (offset +90) — the worst-case shift before the fix.
   */
  public function testSlot25DoesNotDoubleApplyOffset(): void {
    $tz = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-15 14:30:00', $tz);
    $end = $start->modify('+30 minutes');

    $node = $this->createAppointment($start, $end, ['2-5']);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertSame($start->getTimestamp(), $details['start_datetime']->getTimestamp());
    $this->assertSame('2:30 PM', $details['time']);
  }

  /**
   * Multi-slot (1 and 1-5) with min offset 0 — flows through timerange.
   */
  public function testMultiSlotMinOffsetZero(): void {
    $tz = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-06-15 10:00:00', $tz);
    $end = $start->modify('+60 minutes');

    $node = $this->createAppointment($start, $end, ['1', '1-5']);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertSame($start->getTimestamp(), $details['start_datetime']->getTimestamp());
    $this->assertSame('10:00 AM', $details['time']);
  }

  /**
   * Builds an appointment node with the specified timerange and slot values.
   */
  protected function createAppointment(\DateTimeImmutable $start, \DateTimeImmutable $end, array $slots): Node {
    $member = User::create([
      'name' => 'mem_' . uniqid(),
      'mail' => uniqid() . '@example.com',
      'status' => 1,
    ]);
    $member->save();

    $node = Node::create([
      'type' => 'appointment',
      'title' => 'Slot Offset Regression',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_date' => ['value' => $start->format('Y-m-d')],
      'field_appointment_slot' => array_map(fn($s) => ['value' => $s], $slots),
      'field_appointment_timerange' => [[
        'value' => $start->getTimestamp(),
        'end_value' => $end->getTimestamp(),
        'timezone' => 'America/New_York',
        'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
      ]],
    ]);
    $node->save();
    return $node;
  }

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

  protected function attachField(string $field_name, string $label): void {
    if (!FieldConfig::loadByName('node', 'appointment', $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => 'appointment',
        'label' => $label,
      ])->save();
    }
  }

}
