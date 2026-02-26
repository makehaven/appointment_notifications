<?php

namespace Drupal\Tests\appointment_notifications\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests ICS calendar attachment generation and timezone handling.
 *
 * @group appointment_notifications
 */
class IcsCalendarBuilderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Include the .module file to make the ICS functions available.
    require_once dirname(__DIR__, 3) . '/appointment_notifications.module';
  }

  /**
   * Tests the ICS escape function handles special characters correctly.
   */
  public function testIcsEscapeSpecialCharacters(): void {
    $this->assertSame('plain text', _appointment_notifications_ics_escape('plain text'));
    $this->assertSame('semi\\;colon', _appointment_notifications_ics_escape('semi;colon'));
    $this->assertSame('com\\,ma', _appointment_notifications_ics_escape('com,ma'));
    $this->assertSame('back\\\\slash', _appointment_notifications_ics_escape('back\\slash'));
    $this->assertSame('line\\none\\ntwo', _appointment_notifications_ics_escape("line\none\ntwo"));
    $this->assertSame('cr\\nlf', _appointment_notifications_ics_escape("cr\r\nlf"));
  }

  /**
   * Tests Smart Date timestamp conversion to DateTimeImmutable.
   */
  public function testConvertSmartdateValueFromTimestamp(): void {
    // 2026-02-14 19:30:00 UTC = 2026-02-14 14:30:00 EST.
    $timestamp = gmmktime(19, 30, 0, 2, 14, 2026);

    $result = _appointment_notifications_convert_smartdate_value($timestamp, 'America/New_York');

    $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    $this->assertSame('2026-02-14', $result->format('Y-m-d'));
    $this->assertSame('14:30:00', $result->format('H:i:s'));
    $this->assertSame('America/New_York', $result->getTimezone()->getName());
  }

  /**
   * Tests Smart Date string conversion to DateTimeImmutable.
   */
  public function testConvertSmartdateValueFromString(): void {
    $result = _appointment_notifications_convert_smartdate_value('2026-07-15 10:00:00', 'America/New_York');

    $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    $this->assertSame('2026-07-15', $result->format('Y-m-d'));
    $this->assertSame('10:00:00', $result->format('H:i:s'));
  }

  /**
   * Tests slot selections override broad timerange windows.
   */
  public function testScheduleDetailsUseSelectedSlotsOverTimerange(): void {
    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven', 'America/New_York');

    $node = $this->createScheduleNode([
      'field_appointment_date' => '2026-03-02',
      'field_host_start_time' => '2026-03-02 09:00:00',
      'field_appointment_slot' => ['1', '1-5'],
      'field_appointment_timerange' => [
        'value' => '2026-03-02 09:00:00',
        'end_value' => '2026-03-02 13:00:00',
        'timezone' => 'America/New_York',
      ],
    ]);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertInstanceOf(\DateTimeInterface::class, $details['start_datetime']);
    $this->assertInstanceOf(\DateTimeInterface::class, $details['end_datetime']);
    $this->assertSame('09:00', $details['start_datetime']->format('H:i'));
    $this->assertSame('10:00', $details['end_datetime']->format('H:i'));
    $this->assertSame('9:00 AM', $details['time']);
  }

  /**
   * Tests ISO host start timestamps are normalized to site timezone.
   */
  public function testScheduleDetailsNormalizeIsoHostStartToSiteTimezone(): void {
    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven', 'America/New_York');

    $node = $this->createScheduleNode([
      'field_appointment_date' => '2026-03-02',
      'field_host_start_time' => '2026-03-02T07:00:00-08:00',
      'field_appointment_slot' => ['1'],
    ]);

    $details = _appointment_notifications_get_schedule_details($node);

    $this->assertInstanceOf(\DateTimeInterface::class, $details['start_datetime']);
    $this->assertSame('America/New_York', $details['start_datetime']->getTimezone()->getName());
    $this->assertSame('10:00', $details['start_datetime']->format('H:i'));
    $this->assertSame('10:00 AM', $details['time']);
  }

  /**
   * Tests ICS builder produces correct UTC times from Eastern timestamps.
   *
   * This is the critical test: verifies that an appointment at 2:30 PM EST
   * produces DTSTART of 19:30 UTC in the ICS output.
   */
  public function testIcsBuilderProducesCorrectUtcTimesFromEst(): void {
    // Create a mock node with minimal requirements.
    $node = $this->createMockNode(42, 'Test Appointment EST');

    // 2026-02-20 14:30 EST = 2026-02-20 19:30 UTC.
    $est = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-02-20 14:30:00', $est);
    $end = new \DateTimeImmutable('2026-02-20 15:30:00', $est);

    $schedule_details = [
      'start_datetime' => $start,
      'end_datetime' => $end,
    ];

    // We need to mock Drupal services for this function.
    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven');

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'member_scheduled',
      [['email' => 'member@test.com', 'name' => 'Test Member']],
      ['date' => 'Friday, February 20, 2026', 'time' => '2:30 PM', 'purpose' => 'project']
    );

    $this->assertNotNull($attachment, 'Attachment should not be null');
    $this->assertSame('appointment-42.ics', $attachment['filename']);
    $this->assertStringContainsString('text/calendar', $attachment['filemime']);
    $this->assertStringContainsString('method=REQUEST', $attachment['filemime']);

    $content = $attachment['filecontent'];

    // Verify RFC 5545 structure.
    $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
    $this->assertStringContainsString('BEGIN:VEVENT', $content);
    $this->assertStringContainsString('END:VEVENT', $content);
    $this->assertStringContainsString('END:VCALENDAR', $content);

    // Verify correct UTC conversion: 14:30 EST → 19:30 UTC.
    $this->assertStringContainsString('DTSTART:20260220T193000Z', $content,
      'DTSTART should be 19:30 UTC (14:30 EST + 5 hours)');
    $this->assertStringContainsString('DTEND:20260220T203000Z', $content,
      'DTEND should be 20:30 UTC (15:30 EST + 5 hours)');

    // Verify other ICS properties.
    $this->assertStringContainsString('METHOD:REQUEST', $content);
    $this->assertStringContainsString('STATUS:CONFIRMED', $content);
    $this->assertStringContainsString('SUMMARY:Test Appointment EST', $content);
    $this->assertStringContainsString('UID:appointment-42@makehaven.org', $content);
    $this->assertStringContainsString('ATTENDEE;CN=Test Member;ROLE=REQ-PARTICIPANT:mailto:member@test.com', $content);
    $this->assertStringContainsString('ORGANIZER;CN=MakeHaven:mailto:no-reply@makehaven.org', $content);

    // Verify CRLF line endings.
    $this->assertStringContainsString("\r\n", $content, 'ICS must use CRLF line endings');
    $this->assertStringNotContainsString("\r\r\n", $content, 'Should not have double CR');
  }

  /**
   * Tests ICS builder with EDT (Daylight Saving Time) appointment.
   *
   * An appointment at 2:30 PM EDT should be 18:30 UTC (not 19:30).
   */
  public function testIcsBuilderProducesCorrectUtcTimesFromEdt(): void {
    $node = $this->createMockNode(99, 'Test Appointment EDT');

    // 2026-07-15 14:30 EDT = 2026-07-15 18:30 UTC (EDT is UTC-4).
    $et = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable('2026-07-15 14:30:00', $et);
    $end = new \DateTimeImmutable('2026-07-15 15:30:00', $et);

    $schedule_details = [
      'start_datetime' => $start,
      'end_datetime' => $end,
    ];

    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven');

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'member_scheduled',
      [],
      []
    );

    $this->assertNotNull($attachment);

    $content = $attachment['filecontent'];

    // Verify correct UTC conversion: 14:30 EDT → 18:30 UTC.
    $this->assertStringContainsString('DTSTART:20260715T183000Z', $content,
      'DTSTART should be 18:30 UTC (14:30 EDT + 4 hours)');
    $this->assertStringContainsString('DTEND:20260715T193000Z', $content,
      'DTEND should be 19:30 UTC (15:30 EDT + 4 hours)');
  }

  /**
   * Tests ICS builder with cancellation action.
   */
  public function testIcsBuilderCancellationAction(): void {
    $node = $this->createMockNode(55, 'Canceled Appt');

    $start = new \DateTimeImmutable('2026-03-10 10:00:00', new \DateTimeZone('America/New_York'));
    $end = $start->modify('+60 minutes');

    $schedule_details = [
      'start_datetime' => $start,
      'end_datetime' => $end,
    ];

    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven');

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'canceled',
      [],
      []
    );

    $this->assertNotNull($attachment);
    $content = $attachment['filecontent'];

    $this->assertStringContainsString('METHOD:CANCEL', $content);
    $this->assertStringContainsString('STATUS:CANCELLED', $content);
    $this->assertStringContainsString('SEQUENCE:1', $content);
    $this->assertStringContainsString('text/calendar; charset=UTF-8; method=CANCEL', $attachment['filemime']);
  }

  /**
   * Tests ICS builder returns NULL when no start datetime.
   */
  public function testIcsBuilderReturnsNullWithoutStartDatetime(): void {
    $node = $this->createMockNode(1, 'No Time');

    $schedule_details = [
      'start_datetime' => NULL,
      'end_datetime' => NULL,
    ];

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'member_scheduled',
      [],
      []
    );

    $this->assertNull($attachment);
  }

  /**
   * Tests ICS builder adds default end time when only start is provided.
   */
  public function testIcsBuilderDefaultsEndTimeTo60Minutes(): void {
    $node = $this->createMockNode(77, 'Start Only');

    $start = new \DateTimeImmutable('2026-04-01 09:00:00', new \DateTimeZone('America/New_York'));

    $schedule_details = [
      'start_datetime' => $start,
      'end_datetime' => NULL,
    ];

    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven');

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'member_scheduled',
      [],
      []
    );

    $this->assertNotNull($attachment);
    $content = $attachment['filecontent'];

    // 09:00 EDT (April is DST) = 13:00 UTC; end = 10:00 EDT = 14:00 UTC.
    $this->assertStringContainsString('DTSTART:20260401T130000Z', $content);
    $this->assertStringContainsString('DTEND:20260401T140000Z', $content);
  }

  /**
   * Tests ICS content with description and URL fields.
   */
  public function testIcsBuilderIncludesDescriptionAndUrl(): void {
    $node = $this->createMockNode(10, 'Full Details');

    $start = new \DateTimeImmutable('2026-05-01 11:00:00', new \DateTimeZone('America/New_York'));
    $end = $start->modify('+90 minutes');

    $schedule_details = [
      'start_datetime' => $start,
      'end_datetime' => $end,
    ];

    $this->setupDrupalConfigMock('no-reply@makehaven.org', 'MakeHaven');

    $attachment = _appointment_notifications_build_calendar_attachment(
      $node,
      $schedule_details,
      'host_scheduled',
      [],
      [
        'date' => 'Friday, May 1, 2026',
        'time' => '11:00 AM',
        'purpose' => 'checkout',
        'note' => 'Bring safety glasses',
        'link' => 'https://www.makehaven.org/node/10',
      ]
    );

    $this->assertNotNull($attachment);
    $content = $attachment['filecontent'];

    $this->assertStringContainsString('DESCRIPTION:', $content);
    $this->assertStringContainsString('When: Friday\\, May 1\\, 2026 at 11:00 AM', $content);
    $this->assertStringContainsString('Purpose: checkout', $content);
    $this->assertStringContainsString('Notes: Bring safety glasses', $content);
    $this->assertStringContainsString('URL:https://www.makehaven.org/node/10', $content);
  }

  /**
   * Creates a minimal mock Node object.
   */
  protected function createMockNode(int $nid, string $title): \Drupal\node\Entity\Node {
    $node = $this->createMock(\Drupal\node\Entity\Node::class);
    $node->method('id')->willReturn($nid);
    $node->method('getTitle')->willReturn($title);
    return $node;
  }

  /**
   * Creates a node mock tailored for schedule detail tests.
   */
  protected function createScheduleNode(array $field_values): \Drupal\node\Entity\Node {
    $field = static function ($value = NULL, array $items = [], $first = NULL, ?bool $is_empty = NULL) {
      return new class($value, $items, $first, $is_empty) {
        public $value;
        protected array $items;
        protected $first;
        protected ?bool $isEmpty;

        public function __construct($value, array $items, $first, ?bool $is_empty) {
          $this->value = $value;
          $this->items = $items;
          $this->first = $first;
          $this->isEmpty = $is_empty;
        }

        public function isEmpty(): bool {
          if ($this->isEmpty !== NULL) {
            return $this->isEmpty;
          }
          return $this->items === [] && ($this->value === NULL || $this->value === '');
        }

        public function getValue(): array {
          return $this->items;
        }

        public function first() {
          return $this->first;
        }
      };
    };

    $fields = [];
    if (isset($field_values['field_appointment_date'])) {
      $date = (string) $field_values['field_appointment_date'];
      $fields['field_appointment_date'] = $field($date, [], NULL, $date === '');
    }
    if (isset($field_values['field_host_start_time'])) {
      $host_time = (string) $field_values['field_host_start_time'];
      $fields['field_host_start_time'] = $field($host_time, [], NULL, $host_time === '');
    }
    if (isset($field_values['field_appointment_slot'])) {
      $items = [];
      foreach ((array) $field_values['field_appointment_slot'] as $slot_value) {
        $items[] = ['value' => (string) $slot_value];
      }
      $fields['field_appointment_slot'] = $field(NULL, $items, NULL, $items === []);
    }
    if (isset($field_values['field_appointment_timerange']) && is_array($field_values['field_appointment_timerange'])) {
      $range = $field_values['field_appointment_timerange'];
      $range_item = new class($range) {
        public $value;
        public $end_value;
        public $timezone;

        public function __construct(array $range) {
          $this->value = $range['value'] ?? NULL;
          $this->end_value = $range['end_value'] ?? NULL;
          $this->timezone = $range['timezone'] ?? NULL;
        }
      };
      $fields['field_appointment_timerange'] = $field(NULL, [], $range_item, FALSE);
    }

    $empty = $field(NULL, [], NULL, TRUE);
    $node = $this->createMock(\Drupal\node\Entity\Node::class);
    $node->method('id')->willReturn(123);
    $node->method('hasField')->willReturnCallback(static function (string $name) use ($fields): bool {
      return isset($fields[$name]);
    });
    $node->method('get')->willReturnCallback(static function (string $name) use ($fields, $empty) {
      return $fields[$name] ?? $empty;
    });

    return $node;
  }

  /**
   * Sets up Drupal service container mocks for config access.
   */
  protected function setupDrupalConfigMock(string $email_sender, string $site_name, string $site_timezone = 'America/New_York'): void {
    $appointment_config = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $appointment_config->method('get')->willReturnCallback(function ($key) use ($email_sender) {
      if ($key === 'email_sender') {
        return $email_sender;
      }
      return NULL;
    });

    $site_config = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $site_config->method('get')->willReturnCallback(function ($key) use ($site_name) {
      if ($key === 'name') {
        return $site_name;
      }
      return NULL;
    });

    $date_config = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $date_config->method('get')->willReturnCallback(function ($key) use ($site_timezone) {
      if ($key === 'timezone.default') {
        return $site_timezone;
      }
      return NULL;
    });

    $config_factory = $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(function ($name) use ($appointment_config, $site_config, $date_config) {
      if ($name === 'appointment_notifications.settings') {
        return $appointment_config;
      }
      if ($name === 'system.site') {
        return $site_config;
      }
      if ($name === 'system.date') {
        return $date_config;
      }
      return $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    });

    $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
    $container->set('config.factory', $config_factory);
    \Drupal::setContainer($container);
  }

}
