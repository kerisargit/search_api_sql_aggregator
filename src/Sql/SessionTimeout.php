<?php

namespace Drupal\search_api_sql_aggregator\Sql;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Per-run SQL statement timeout for the session, restored afterwards.
 *
 * Session-scoped (not SET LOCAL): custom_sql runs outside any transaction.
 * The previous value must be restored because cron runs other modules'
 * queries on the same connection afterwards.
 */
class SessionTimeout {

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  protected function isMariaDb(): bool {
    return method_exists($this->database, 'isMariaDb') && $this->database->isMariaDb();
  }

  public function set(int $ms) {
    if ($ms <= 0) {
      return NULL;
    }
    try {
      $driver = $this->database->driver();
      // MariaDB has max_statement_time (seconds) instead of MySQL's
      // max_execution_time (ms), which only applies to SELECT anyway.
      if ($driver === 'mysql' && $this->isMariaDb()) {
        $original = $this->database->query('SELECT @@SESSION.max_statement_time')->fetchField();
        $this->database->query('SET SESSION max_statement_time = ' . round($ms / 1000, 3));
        return $original === FALSE ? NULL : $original;
      }
      if ($driver === 'mysql') {
        $original = $this->database->query('SELECT @@SESSION.max_execution_time')->fetchField();
        $this->database->query('SET SESSION max_execution_time = ' . (int) $ms);
        return $original === FALSE ? NULL : $original;
      }
      if ($driver === 'pgsql') {
        $original = $this->database->query('SHOW statement_timeout')->fetchField();
        $this->database->query('SET SESSION statement_timeout = ' . (int) $ms);
        return $original === FALSE ? NULL : $original;
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('Could not set SQL timeout: @msg', ['@msg' => $e->getMessage()]);
    }
    return NULL;
  }

  public function reset($original): void {
    if ($original === NULL) {
      return;
    }
    try {
      $driver = $this->database->driver();
      if ($driver === 'mysql' && $this->isMariaDb()) {
        $this->database->query('SET SESSION max_statement_time = ' . (float) $original);
      }
      elseif ($driver === 'mysql') {
        $this->database->query('SET SESSION max_execution_time = ' . (int) $original);
      }
      elseif ($driver === 'pgsql') {
        $this->database->query("SET SESSION statement_timeout = '" . $original . "'");
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('Could not restore original SQL timeout: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
