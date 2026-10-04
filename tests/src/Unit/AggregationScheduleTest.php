<?php

namespace Drupal\Tests\search_api_sql_aggregator\Unit;

use Drupal\search_api_sql_aggregator\Schedule\AggregationSchedule;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_sql_aggregator
 * @coversDefaultClass \Drupal\search_api_sql_aggregator\Schedule\AggregationSchedule
 */
class AggregationScheduleTest extends UnitTestCase {

  private const MSK = 'Europe/Moscow';

  private static function ts(string $local, string $tz = self::MSK): int {
    return (new \DateTimeImmutable($local, new \DateTimeZone($tz)))->getTimestamp();
  }

  private static function schedule(string $type, int $interval = 0, string $time = '02:00', int $dow = 1, string $tz = self::MSK): AggregationSchedule {
    return new AggregationSchedule($type, $interval, $time, $dow, new \DateTimeZone($tz));
  }

  public function testIntervalZeroIsAlwaysDueAndHasNoNextRun(): void {
    $s = self::schedule('interval', 0);
    $now = self::ts('2026-10-05 12:00');
    $this->assertTrue($s->isDue($now - 1, $now));
    $this->assertNull($s->nextRun($now - 1, $now));
  }

  public function testIntervalNeverRunIsDueImmediately(): void {
    $s = self::schedule('interval', 3600);
    $now = self::ts('2026-10-05 12:00');
    $this->assertTrue($s->isDue(0, $now));
    $this->assertSame($now, $s->nextRun(0, $now));
  }

  public function testIntervalNotElapsedIsNotDue(): void {
    $s = self::schedule('interval', 3600);
    $now = self::ts('2026-10-05 12:00');
    $this->assertFalse($s->isDue($now - 100, $now));
    $this->assertSame($now - 100 + 3600, $s->nextRun($now - 100, $now));
  }

  public function testIntervalElapsedExactlyIsDue(): void {
    $s = self::schedule('interval', 3600);
    $now = self::ts('2026-10-05 12:00');
    $this->assertTrue($s->isDue($now - 3600, $now));
  }

  public function testDailyDueAfterTodaysTime(): void {
    $s = self::schedule('daily');
    $this->assertTrue($s->isDue(self::ts('2026-10-04 02:30'), self::ts('2026-10-05 03:00')));
  }

  public function testDailyNotDueTwiceTheSameDay(): void {
    $s = self::schedule('daily');
    $now = self::ts('2026-10-05 03:00');
    $last = self::ts('2026-10-05 02:10');
    $this->assertFalse($s->isDue($last, $now));
    $this->assertSame(self::ts('2026-10-06 02:00'), $s->nextRun($last, $now));
  }

  public function testDailyNotDueBeforeTodaysTimeWhenYesterdayRan(): void {
    $s = self::schedule('daily');
    $now = self::ts('2026-10-05 01:00');
    $last = self::ts('2026-10-04 02:30');
    $this->assertFalse($s->isDue($last, $now));
    $this->assertSame(self::ts('2026-10-05 02:00'), $s->nextRun($last, $now));
  }

  public function testDailyCatchesUpAMissedDay(): void {
    $s = self::schedule('daily');
    $now = self::ts('2026-10-05 01:00');
    $last = self::ts('2026-10-03 02:30');
    $this->assertTrue($s->isDue($last, $now));
    $this->assertLessThanOrEqual($now, $s->nextRun($last, $now));
  }

  public function testDailyFirstEverRunIsDue(): void {
    $this->assertTrue(self::schedule('daily')->isDue(0, self::ts('2026-10-05 03:00')));
  }

  public function testWeeklyDueOnTheDayAfterTheTime(): void {
    $s = self::schedule('weekly', 0, '02:00', 1);
    $this->assertTrue($s->isDue(self::ts('2026-09-28 02:30'), self::ts('2026-10-05 03:00')));
  }

  public function testWeeklyNotDueMidWeek(): void {
    $s = self::schedule('weekly', 0, '02:00', 1);
    $now = self::ts('2026-10-04 12:00');
    $last = self::ts('2026-09-28 02:30');
    $this->assertFalse($s->isDue($last, $now));
    $this->assertSame(self::ts('2026-10-05 02:00'), $s->nextRun($last, $now));
  }

  public function testWeeklyBeforeTheTimeOnTheDayLooksAtLastWeek(): void {
    $s = self::schedule('weekly', 0, '02:00', 1);
    $now = self::ts('2026-10-05 01:00');
    $this->assertFalse($s->isDue(self::ts('2026-09-28 02:30'), $now));
    $this->assertTrue($s->isDue(self::ts('2026-09-21 02:30'), $now));
  }

  public function testWeeklySundayIsDayZero(): void {
    $s = self::schedule('weekly', 0, '02:00', 0);
    $now = self::ts('2026-10-04 12:00');
    $this->assertTrue($s->isDue(self::ts('2026-09-27 03:00'), $now));
    $this->assertFalse($s->isDue(self::ts('2026-10-04 02:30'), $now));
    $this->assertSame(self::ts('2026-10-11 02:00'), $s->nextRun(self::ts('2026-10-04 02:30'), $now));
  }

  public function testTimeIsEvaluatedInTheScheduleTimezone(): void {
    $now = self::ts('2026-10-04 23:30', 'UTC');
    $last = self::ts('2026-10-04 03:00', 'UTC');
    $this->assertTrue(self::schedule('daily', 0, '02:00', 1, self::MSK)->isDue($last, $now));
    $this->assertFalse(self::schedule('daily', 0, '02:00', 1, 'UTC')->isDue($last, $now));
  }

  public function testDailyKeepsWallClockTimeAcrossDstChange(): void {
    $s = self::schedule('daily', 0, '04:00', 1, 'Europe/Berlin');
    $now = self::ts('2026-10-25 05:00', 'Europe/Berlin');
    $last = self::ts('2026-10-25 04:10', 'Europe/Berlin');
    $this->assertFalse($s->isDue($last, $now));
    $this->assertSame(self::ts('2026-10-26 04:00', 'Europe/Berlin'), $s->nextRun($last, $now));
    $this->assertTrue($s->isDue(self::ts('2026-10-24 04:10', 'Europe/Berlin'), $now));
  }

  /**
   * @dataProvider provideMalformedTimes
   */
  public function testMalformedTimeIsNeverDue(string $time): void {
    $s = self::schedule('daily', 0, $time);
    $now = self::ts('2026-10-05 12:00');
    $this->assertFalse($s->isDue(0, $now));
    $this->assertNull($s->nextRun(0, $now));
  }

  public static function provideMalformedTimes(): array {
    return [
      'hour 25' => ['25:00'],
      'minute 60' => ['02:60'],
      'no colon' => ['0200'],
      'empty' => [''],
      'single digit hour' => ['2:00'],
    ];
  }

  public function testUnknownTypeIsNeverDue(): void {
    $s = self::schedule('hourly');
    $now = self::ts('2026-10-05 12:00');
    $this->assertFalse($s->isDue(0, $now));
    $this->assertNull($s->nextRun(0, $now));
  }

}
