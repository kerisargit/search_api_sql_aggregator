<?php

namespace Drupal\search_api_sql_aggregator\Schedule;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Interval/daily/weekly rules, evaluated in the site time zone.
 *
 * A missed daily/weekly occurrence is caught up on the next trigger.
 */
final class AggregationSchedule {

  public const TYPES = ['interval', 'daily', 'weekly'];

  public function __construct(
    private readonly string $type,
    private readonly int $interval,
    private readonly string $time,
    private readonly int $dayOfWeek,
    private readonly \DateTimeZone $timezone,
  ) {}

  public static function fromConfig(ConfigFactoryInterface $configFactory): self {
    $config = $configFactory->get('search_api_sql_aggregator.settings');
    // Non-scalar values (a corrupted config) fall back to the defaults.
    $get = fn(string $key, $default) => \is_scalar($config->get($key)) ? $config->get($key) : $default;
    return new self(
      (string) $get('schedule_type', 'interval'),
      (int) $get('schedule_interval', 0),
      (string) $get('schedule_time', '02:00'),
      (int) $get('schedule_day_of_week', 1),
      self::siteTimezone($configFactory),
    );
  }

  public static function siteTimezone(ConfigFactoryInterface $configFactory): \DateTimeZone {
    $name = $configFactory->get('system.date')->get('timezone.default');
    $name = \is_string($name) ? $name : '';
    try {
      return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
    }
    catch (\Exception) {
      return new \DateTimeZone(date_default_timezone_get());
    }
  }

  public function getTimezone(): \DateTimeZone {
    return $this->timezone;
  }

  public function isDue(int $lastRun, int $now): bool {
    if ($this->type === 'interval') {
      return $this->interval <= 0 || $lastRun <= 0 || ($now - $lastRun) >= $this->interval;
    }
    $occurrence = $this->latestOccurrence($now);
    return $occurrence !== NULL && $lastRun < $occurrence;
  }

  public function nextRun(int $lastRun, int $now): ?int {
    if ($this->type === 'interval') {
      if ($this->interval <= 0) {
        return NULL;
      }
      return $lastRun > 0 ? $lastRun + $this->interval : $now;
    }
    $occurrence = $this->latestOccurrence($now);
    if ($occurrence === NULL) {
      return NULL;
    }
    if ($lastRun < $occurrence) {
      return $occurrence;
    }
    $step = $this->type === 'daily' ? '+1 day' : '+1 week';
    return $this->at($occurrence)->modify($step)->getTimestamp();
  }

  private function latestOccurrence(int $now): ?int {
    if (!\in_array($this->type, ['daily', 'weekly'], TRUE)
      || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $this->time, $m)) {
      return NULL;
    }
    $candidate = $this->at($now)->setTime((int) $m[1], (int) $m[2]);

    if ($this->type === 'weekly') {
      $back = ((int) $candidate->format('w') - ($this->dayOfWeek % 7) + 7) % 7;
      if ($back > 0) {
        $candidate = $candidate->modify("-{$back} days")->setTime((int) $m[1], (int) $m[2]);
      }
    }

    if ($candidate->getTimestamp() > $now) {
      $step = $this->type === 'daily' ? '-1 day' : '-1 week';
      $candidate = $candidate->modify($step)->setTime((int) $m[1], (int) $m[2]);
    }
    return $candidate->getTimestamp();
  }

  private function at(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
  }

}
