<?php

namespace Drupal\search_api_sql_aggregator\Sql;

use Drupal\search_api_sql_aggregator\Exception\OperationValidationException;
use Drupal\search_api_sql_aggregator\Service\OperationValidator;

/**
 * Classifies a custom_sql statement: read-only, or a write to one table.
 *
 * A guard against mistakes, not a SQL parser: anything it cannot classify
 * confidently is refused. Checking the write target against the Search API
 * table whitelist is the validator's job.
 */
final class CustomSqlClassifier {

  public const READONLY_COMMANDS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'];

  public const WRITE_COMMANDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE'];

  /**
   * @return array{command: string, target: string|null}
   *   The leading command, and the write-target table (NULL for read-only).
   *
   * @throws \Drupal\search_api_sql_aggregator\Exception\OperationValidationException
   */
  public static function classify(string $rawSql): array {
    $sql = self::assertSingleStatement($rawSql, 'custom_sql');

    $stripped = self::stripLeadingComments($sql);
    $command  = self::leadingCommand($stripped);

    if ($command !== NULL && \in_array($command, self::READONLY_COMMANDS, TRUE)) {
      // EXPLAIN ANALYZE runs the statement it explains (PostgreSQL, MySQL
      // 8.0.18+), so it is not read-only.
      if (\in_array($command, ['EXPLAIN', 'DESCRIBE', 'DESC'], TRUE) && \preg_match('/\bANALY[SZ]E\b/i', $stripped)) {
        throw new OperationValidationException(
          "custom_sql: '{$command} ANALYZE' executes the explained statement and is not allowed."
        );
      }
      if (\preg_match('/\bINTO\b/i', $stripped)) {
        throw new OperationValidationException(
          "custom_sql: '{$command}' statements may not contain INTO (e.g. INTO OUTFILE/DUMPFILE, or a " .
          'Postgres SELECT ... INTO table) — these write outside the database or create a new table, ' .
          'neither of which this check can verify. Rewrite without INTO.'
        );
      }
      return ['command' => $command, 'target' => NULL];
    }

    if ($command === 'WITH') {
      throw new OperationValidationException(
        'custom_sql: statements starting with WITH (common table expressions) are not supported — this ' .
        'check cannot reliably tell whether a WITH block ends in a read or a write. Rewrite using a ' .
        'subquery instead of a CTE.'
      );
    }

    if ($command === NULL || !\in_array($command, self::WRITE_COMMANDS, TRUE)) {
      throw new OperationValidationException(
        'custom_sql: unrecognised or unsupported SQL command' .
        ($command !== NULL ? " '" . self::truncate($command) . "'" : '') . '. Supported: ' .
        'read-only statements (SELECT/SHOW/EXPLAIN/DESCRIBE — any table) or a single-table write ' .
        '(INSERT/UPDATE/DELETE/REPLACE/TRUNCATE — target must be a Search API table).'
      );
    }

    $target = self::writeTarget($command, $stripped);
    if ($target === NULL) {
      throw new OperationValidationException(
        "custom_sql: could not confidently identify a single write-target table for this '{$command}' " .
        'statement. Supported forms: INSERT INTO {table} ..., UPDATE {table} SET ... (no JOIN/alias), ' .
        'DELETE FROM {table} ... (no JOIN/multi-table list), REPLACE INTO {table} ..., ' .
        'TRUNCATE [TABLE] {table} — single plain table reference only. Sources referenced elsewhere in ' .
        'the statement (JOIN, subqueries, WHERE) are not restricted.'
      );
    }

    return ['command' => $command, 'target' => $target];
  }

  /**
   * Trims a trailing ';' and refuses an empty statement or several of them.
   */
  public static function assertSingleStatement(string $rawSql, string $context): string {
    $sql = \trim($rawSql);
    if ($sql === '') {
      throw new OperationValidationException("{$context}: SQL statement is empty.");
    }
    $sql = \rtrim($sql, "; \t\n\r");
    if (\strpos($sql, ';') !== FALSE) {
      throw new OperationValidationException(
        "{$context}: only a single SQL statement is supported per block (found an embedded ';'). " .
        'Use multiple Custom SQL blocks for multiple statements.'
      );
    }
    return $sql;
  }

  private static function stripLeadingComments(string $sql): string {
    do {
      $before = $sql;
      $sql = \ltrim($sql);
      $sql = \preg_replace('/\A--[^\n]*\n?/', '', $sql, 1) ?? $sql;
      $sql = \preg_replace('/\A\/\*.*?\*\//s', '', $sql, 1) ?? $sql;
    } while ($sql !== $before);
    return \ltrim($sql);
  }

  private static function leadingCommand(string $sql): ?string {
    if (!\preg_match('/\A([A-Za-z]+)\b/', $sql, $m)) {
      return NULL;
    }
    return \strtoupper($m[1]);
  }

  private static function writeTarget(string $command, string $sql): ?string {
    // The trailing lookahead rejects "name.something": a schema/database
    // qualifier, or MySQL's multi-table "DELETE FROM t.*, other.* USING".
    $tableRef = '(?>\{([A-Za-z0-9_]{1,128})\}|`([A-Za-z0-9_]{1,128})`|"([A-Za-z0-9_]{1,128})"|([A-Za-z0-9_]{1,128}))(?!\s*\.)';

    switch ($command) {
      case 'INSERT':
        $pattern = '/\AINSERT\s+(?:OR\s+(?:REPLACE|IGNORE|ABORT|FAIL|ROLLBACK)\s+)?INTO\s+' . $tableRef . '/i';
        break;

      case 'REPLACE':
        $pattern = '/\AREPLACE\s+INTO\s+' . $tableRef . '/i';
        break;

      case 'UPDATE':
        $pattern = '/\AUPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)?' . $tableRef . '\s+SET\b/i';
        break;

      case 'DELETE':
        $pattern = '/\ADELETE\s+FROM\s+' . $tableRef . '(?!\s*,)/i';
        break;

      case 'TRUNCATE':
        $pattern = '/\ATRUNCATE\s+(?:TABLE\s+)?' . $tableRef . '\s*\z/i';
        break;

      default:
        return NULL;
    }

    if (!\preg_match($pattern, $sql, $m)) {
      return NULL;
    }
    foreach ([1, 2, 3, 4] as $i) {
      if (isset($m[$i]) && $m[$i] !== '') {
        return $m[$i];
      }
    }
    return NULL;
  }

  private static function truncate(string $value): string {
    $value = \preg_replace('/[\'\p{Cc}\p{Cf}]/u', '', $value) ?? $value;
    return \mb_strlen($value) > OperationValidator::MAX_MESSAGE_VALUE_LENGTH
      ? \mb_substr($value, 0, OperationValidator::MAX_MESSAGE_VALUE_LENGTH) . '…'
      : $value;
  }

}
