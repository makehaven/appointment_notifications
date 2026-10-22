<?php

namespace Drupal\Tests\appointment_notifications\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * A tool going out of service tells its upcoming appointments.
 *
 * @group appointment_notifications
 */
class AppointmentToolOfflineNoticeTest extends KernelTestBase {

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
    'taxonomy',
    'profile',
    'appointment_notifications',
  ];

  /**
   * Status terms by label.
   *
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  protected array $statuses = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('profile');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'appointment_notifications']);

    \Drupal::configFactory()->getEditable('system.mail')->set('interface.default', 'test_mail_collector')->save();
    \Drupal::configFactory()->getEditable('appointment_notifications.settings')
      ->set('development_mode', FALSE)->set('email_logging', FALSE)
      ->set('calendar_invites_enabled', FALSE)->set('tool_offline_notice_enabled', TRUE)->save();

    foreach (['appointment' => 'Appointment', 'item' => 'Item'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    foreach (['item_status', 'badges'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => $vid])->save();
    }
    foreach (['Operational', 'Degraded', 'Offline', 'Out of Service'] as $label) {
      $this->statuses[$label] = Term::create(['vid' => 'item_status', 'name' => $label]);
      $this->statuses[$label]->save();
    }

    $this->addField('item', 'field_item_status', 'entity_reference', ['target_type' => 'taxonomy_term']);
    $this->addField('item', 'field_member_badges', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->addField('appointment', 'field_appointment_status', 'list_string', [
      'allowed_values' => ['scheduled' => 'scheduled', 'canceled' => 'canceled'],
    ]);
    $this->addField('appointment', 'field_appointment_timerange', 'smartdate', []);
    $this->addField('appointment', 'field_appointment_date', 'datetime', ['datetime_type' => 'date']);
    $this->addField('appointment', 'field_appointment_host', 'entity_reference', ['target_type' => 'user']);
    $this->addField('appointment', 'field_appointment_attendees', 'entity_reference', ['target_type' => 'user'], -1);
    $this->addField('appointment', 'field_appointment_badges', 'entity_reference', ['target_type' => 'taxonomy_term'], -1);
    $this->addField('appointment', 'field_appointment_result', 'list_string', [
      'allowed_values' => ['met_successful' => 'Success'],
    ]);
  }

  /**
   * Everyone on a matching upcoming appointment hears once per outage.
   */
  public function testOfflineNoticeReachesUpcomingAppointmentsOnly(): void {
    $cnc = Term::create(['vid' => 'badges', 'name' => 'CNC Router']);
    $cnc->save();
    $laser = Term::create(['vid' => 'badges', 'name' => 'Laser']);
    $laser->save();

    $member = $this->createUser('member', 'member@example.com');
    $host = $this->createUser('host', 'host@example.com');
    $guest = $this->createUser('guest', 'guest@example.com');

    $tool = $this->createTool('Shapeoko', 'Operational', $cnc);
    $this->createTool('Big CNC', 'Operational', $cnc);

    $now = \Drupal::time()->getRequestTime();
    $affected = $this->createAppointment('CNC checkout', $member, $host, $cnc, $now + 3 * 86400, [$guest]);
    $this->createAppointment('Laser checkout', $member, $host, $laser, $now + 3 * 86400);
    $this->createAppointment('Past CNC', $member, $host, $cnc, $now - 86400);
    $this->createAppointment('Canceled CNC', $member, $host, $cnc, $now + 86400, [], 'canceled');
    $this->createAppointment('Far CNC', $member, $host, $cnc, $now + 200 * 86400);

    $this->setStatus($tool, 'Offline');

    $mails = $this->toolMails();
    $this->assertCount(3, $mails, 'Booker, attendee and facilitator each get one notice.');
    $recipients = array_column($mails, 'to');
    sort($recipients);
    $this->assertSame(['guest@example.com', 'host@example.com', 'member@example.com'], $recipients);
    $this->assertStringContainsString('Shapeoko is Offline', $mails[0]['subject']);
    $this->assertStringContainsString('CNC checkout', $mails[0]['body']);
    $this->assertStringContainsString('Big CNC', $mails[0]['body']);
    $this->assertStringContainsString('/node/' . $affected->id(), $mails[0]['body']);

    // Still down, just a different label: no second round.
    $this->setStatus($tool, 'Out of Service');
    $this->assertCount(3, $this->toolMails());
  }

  /**
   * A tool that is still usable does not send anything.
   */
  public function testDegradedSendsNothing(): void {
    $cnc = Term::create(['vid' => 'badges', 'name' => 'CNC Router']);
    $cnc->save();
    $member = $this->createUser('member', 'member@example.com');
    $host = $this->createUser('host', 'host@example.com');
    $tool = $this->createTool('Shapeoko', 'Operational', $cnc);
    $this->createAppointment('CNC checkout', $member, $host, $cnc, \Drupal::time()->getRequestTime() + 86400);

    $this->setStatus($tool, 'Degraded');
    $this->assertCount(0, $this->toolMails());

    \Drupal::configFactory()->getEditable('appointment_notifications.settings')->set('tool_offline_notice_enabled', FALSE)->save();
    $this->setStatus($tool, 'Offline');
    $this->assertCount(0, $this->toolMails(), 'The setting turns the notice off.');
  }

  /**
   * Tool-offline mails captured so far.
   */
  protected function toolMails(): array {
    return array_values(array_filter(
      \Drupal::state()->get('system.test_mail_collector', []),
      static fn ($mail) => $mail['id'] === 'appointment_notifications_appointment_tool_offline',
    ));
  }

  protected function setStatus(Node $tool, string $label): void {
    $tool = Node::load($tool->id());
    $tool->set('field_item_status', $this->statuses[$label]->id());
    $tool->save();
  }

  protected function createUser(string $name, string $mail): User {
    $user = User::create(['name' => $name, 'mail' => $mail, 'status' => 1]);
    $user->save();
    return $user;
  }

  protected function createTool(string $title, string $status, Term $badge): Node {
    $tool = Node::create([
      'type' => 'item',
      'title' => $title,
      'status' => 1,
      'field_item_status' => $this->statuses[$status]->id(),
      'field_member_badges' => [$badge->id()],
    ]);
    $tool->save();
    return $tool;
  }

  protected function createAppointment(string $title, User $member, User $host, Term $badge, int $start, array $attendees = [], string $status = 'scheduled'): Node {
    $appointment = Node::create([
      'type' => 'appointment',
      'title' => $title,
      'uid' => $member->id(),
      'status' => 1,
      'field_appointment_status' => $status,
      'field_appointment_timerange' => ['value' => $start, 'end_value' => $start + 3600, 'duration' => 60],
      'field_appointment_host' => $host->id(),
      'field_appointment_attendees' => array_map(static fn (User $u) => $u->id(), $attendees),
      'field_appointment_badges' => [$badge->id()],
    ]);
    $appointment->save();
    return $appointment;
  }

  protected function addField(string $bundle, string $name, string $type, array $settings, int $cardinality = 1): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'cardinality' => $cardinality,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $name,
    ])->save();
  }

}
