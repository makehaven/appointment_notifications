<?php

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * Tests secure appointment cancel links and cancellation field updates.
 *
 * @group appointment_notifications
 */
class AppointmentCancelLinkTest extends KernelTestBase {

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
   * Verifies member/host links are signed and cancellation fields are updated.
   */
  public function testCancelLinksAndCancellationMutation(): void {
    $member = User::create([
      'name' => 'cancel_member',
      'mail' => 'cancel-member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'cancel_host',
      'mail' => 'cancel-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Cancel Link Test',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => '2026-03-10'],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_reservation_cancellation' => [],
    ]);
    $appointment->save();

    $member_link = _appointment_notifications_cancel_link($appointment, 'member');
    $host_link = _appointment_notifications_cancel_link($appointment, 'host');

    $this->assertNotEmpty($member_link, 'Member cancellation link is generated.');
    $this->assertNotEmpty($host_link, 'Host cancellation link is generated.');

    $member_parts = $this->extractTokenParts($member_link);
    $this->assertTrue(
      _appointment_notifications_cancel_token_is_valid(
        $appointment,
        $member_parts['audience'],
        $member_parts['expires'],
        $member_parts['token'],
        'cancel-member@example.com'
      ),
      'Member token validates for member email.'
    );
    $this->assertFalse(
      _appointment_notifications_cancel_token_is_valid(
        $appointment,
        $member_parts['audience'],
        $member_parts['expires'],
        $member_parts['token'],
        'cancel-host@example.com'
      ),
      'Member token does not validate for host email.'
    );

    $this->assertTrue(_appointment_notifications_cancel_appointment($appointment), 'Cancellation helper updates appointment.');

    $reloaded = Node::load($appointment->id());
    $this->assertSame('canceled', (string) $reloaded->get('field_appointment_status')->value);
    $cancel_values = array_column($reloaded->get('field_reservation_cancellation')->getValue(), 'value');
    $this->assertContains('Cancel', $cancel_values);
  }

  /**
   * Verifies appointment page cancel action appears for member/host only.
   */
  public function testCancelActionShownForMemberAndHostOnly(): void {
    $member = User::create([
      'name' => 'cancel_action_member',
      'mail' => 'cancel-action-member@example.com',
      'status' => 1,
    ]);
    $member->save();

    $host = User::create([
      'name' => 'cancel_action_host',
      'mail' => 'cancel-action-host@example.com',
      'status' => 1,
    ]);
    $host->save();

    $other = User::create([
      'name' => 'cancel_action_other',
      'mail' => 'cancel-action-other@example.com',
      'status' => 1,
    ]);
    $other->save();

    $appointment = Node::create([
      'type' => 'appointment',
      'title' => 'Cancel Action Render Test',
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => 'scheduled',
      'field_appointment_date' => ['value' => '2026-03-11'],
      'field_appointment_host' => ['target_id' => $host->id()],
      'field_reservation_cancellation' => [],
      'field_appointment_purpose' => 'project',
      'field_appointment_feedback' => '',
      'field_appointment_result' => 'met_successful',
      'field_appointment_note' => '',
    ]);
    $appointment->save();

    $this->container->get('current_user')->setAccount($member);
    $member_build = [];
    appointment_notifications_entity_view($member_build, $appointment, 'full', 'en');
    $this->assertArrayHasKey('appointment_cancel_action', $member_build);
    $member_url = $member_build['appointment_cancel_action']['link']['#url']->toString();
    $this->assertStringContainsString('/cancel/member/', $member_url);

    $this->container->get('current_user')->setAccount($host);
    $host_build = [];
    appointment_notifications_entity_view($host_build, $appointment, 'full', 'en');
    $this->assertArrayHasKey('appointment_cancel_action', $host_build);
    $host_url = $host_build['appointment_cancel_action']['link']['#url']->toString();
    $this->assertStringContainsString('/cancel/host/', $host_url);

    $this->container->get('current_user')->setAccount($other);
    $other_build = [];
    appointment_notifications_entity_view($other_build, $appointment, 'full', 'en');
    $this->assertArrayNotHasKey('appointment_cancel_action', $other_build);

    $appointment->set('field_appointment_status', 'canceled');
    $appointment->save();
    $this->container->get('current_user')->setAccount($member);
    $cancelled_build = [];
    appointment_notifications_entity_view($cancelled_build, $appointment, 'full', 'en');
    $this->assertArrayNotHasKey('appointment_cancel_action', $cancelled_build);
  }

  /**
   * Extracts token params from generated cancel URL.
   */
  protected function extractTokenParts(string $cancel_link): array {
    $path = parse_url($cancel_link, PHP_URL_PATH) ?: '';
    $segments = explode('/', trim($path, '/'));
    $count = count($segments);

    return [
      'audience' => $segments[$count - 3] ?? '',
      'expires' => (int) ($segments[$count - 2] ?? 0),
      'token' => $segments[$count - 1] ?? '',
    ];
  }

  /**
   * Creates fields used by cancellation flow.
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
