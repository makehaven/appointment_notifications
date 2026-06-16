<?php

namespace Drupal\Tests\appointment_notifications\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the robust host start-time parser used by schedule-details time math.
 *
 * Regression: a bare numeric/unparseable host start-time value made the raw
 * new \DateTimeImmutable($value) throw, logging an unexplained error ~hourly
 * and forcing a fall back that re-applied the slot offset. The parser now
 * handles numeric timestamps, recovers strtotime-parseable strings, and fails
 * soft to NULL (with a value-naming warning) instead of throwing.
 *
 * @group appointment_notifications
 */
class HostStartTimeParseTest extends UnitTestCase {

  /**
   * Spy logger capturing the fail-soft warning.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 3) . '/appointment_notifications.module';

    // The fail-soft branch calls \Drupal::logger('appointment_notifications'),
    // i.e. logger.factory->get($channel). Wire a minimal container so that
    // resolves to a spy logger.
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->logger);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $factory);
    \Drupal::setContainer($container);
  }

  /**
   * A bare numeric unix timestamp is parsed (previously threw).
   */
  public function testNumericTimestampParses(): void {
    // 2026-06-15 18:00:00 UTC = 2026-06-15 14:00:00 EDT.
    $timestamp = gmmktime(18, 0, 0, 6, 15, 2026);

    $result = _appointment_notifications_parse_host_start_time((string) $timestamp, 'America/New_York', 42);

    $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    $this->assertSame($timestamp, $result->getTimestamp());
    $this->assertSame('2:00 PM', $result->format('g:i A'));
  }

  /**
   * A normal datetime string parses unchanged.
   */
  public function testDatetimeStringParses(): void {
    $result = _appointment_notifications_parse_host_start_time('2026-06-15 14:30:00', 'America/New_York', 42);

    $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    $this->assertSame('2:30 PM', $result->format('g:i A'));
  }

  /**
   * Empty / null values fail soft to NULL without logging.
   */
  public function testEmptyValueReturnsNull(): void {
    $this->logger->expects($this->never())->method('warning');

    $this->assertNull(_appointment_notifications_parse_host_start_time('', 'America/New_York', 42));
    $this->assertNull(_appointment_notifications_parse_host_start_time(NULL, 'America/New_York', 42));
  }

  /**
   * An unparseable value returns NULL and logs a value-naming warning.
   *
   * It must not throw, and must not log an opaque error.
   */
  public function testGarbageValueFailsSoftWithWarning(): void {
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Could not parse host start time'),
        $this->callback(fn($context) => ($context['@value'] ?? NULL) === 'definitely-not-a-time' && ($context['@nid'] ?? NULL) === 99)
      );

    $result = _appointment_notifications_parse_host_start_time('definitely-not-a-time', 'America/New_York', 99);

    $this->assertNull($result);
  }

}
