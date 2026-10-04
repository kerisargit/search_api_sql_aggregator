<?php

namespace Drupal\search_api_sql_aggregator\Sql;

use Drupal\Core\Database\Connection;

/**
 * Builds the dialect-specific SQL of the structured operations.
 *
 * Inputs are already validated (OperationValidator); this only shapes SQL.
 * js/builder.js mirrors these statements for its preview: keep them in sync.
 */
class SqlBuilder {

  public function __construct(
    protected Connection $database,
  ) {}

  protected function buildHierarchyJoin(array $src): string {
    $table    = (string) ($src['table']         ?? 'taxonomy_term__parent');
    $entCol   = $this->database->escapeField((string) ($src['entity_col'] ?? 'entity_id'));
    $checkDel = (bool)   ($src['check_deleted'] ?? TRUE);

    $join = "LEFT JOIN {{$table}} h ON h.{$entCol} = s.value";
    if ($checkDel) {
      $join .= ' AND h.deleted = 0';
    }
    return $join;
  }

  protected function buildHierarchyExpr(array $src): string {
    $parentCol = $this->database->escapeField((string) ($src['parent_col'] ?? 'parent_target_id'));
    $nullValue = $src['null_value'] ?? 0;

    $parentRef = is_numeric($nullValue) && $nullValue !== ''
      ? "NULLIF(h.{$parentCol}, " . (int) $nullValue . ')'
      : "h.{$parentCol}";

    $castType = $this->database->driver() === 'mysql' ? 'UNSIGNED' : 'INTEGER';
    return "CAST(COALESCE({$parentRef}, s.value) AS {$castType})";
  }

  public function getInsertSql(string $parent, string $child, bool $hier, array $hierSrc = []): string {
    if ($hier) {
      $join = $this->buildHierarchyJoin($hierSrc);
      $expr = $this->buildHierarchyExpr($hierSrc);
      return "INSERT INTO {{$parent}} (item_id, value)
        SELECT DISTINCT
          s.item_id,
          {$expr} AS val
        FROM {{$child}} s
        {$join}
        WHERE {$expr} <> 0
          AND NOT EXISTS (
            SELECT 1 FROM {{$parent}} a
            WHERE a.item_id = s.item_id
              AND a.value = {$expr}
          )";
    }

    return "INSERT INTO {{$parent}} (item_id, value)
      SELECT s.item_id, s.value
      FROM {{$child}} s
      WHERE NOT EXISTS (
        SELECT 1 FROM {{$parent}} a
        WHERE a.item_id = s.item_id AND a.value = s.value
      )";
  }

  public function getUpdateSql(bool $hier, string $main, string $escapedCol, string $union, array $hierSrc = []): string {
    if ($hier) {
      $join = $this->buildHierarchyJoin($hierSrc);
      $expr = $this->buildHierarchyExpr($hierSrc);
      $srcSql = "SELECT
          s.item_id,
          MIN({$expr}) AS val
        FROM ( {$union} ) AS s
        {$join}
        WHERE {$expr} <> 0
        GROUP BY s.item_id";
    }
    else {
      $srcSql = "SELECT item_id, MIN(value) AS val
        FROM ( {$union} ) AS flatsrc
        GROUP BY item_id";
    }

    if ($this->database->driver() === 'mysql') {
      return "UPDATE {{$main}} AS main
        JOIN ( {$srcSql} ) AS src ON src.item_id = main.item_id
        SET main.{$escapedCol} = src.val";
    }

    return "UPDATE {{$main}} AS main
      SET {$escapedCol} = src.val
      FROM ( {$srcSql} ) AS src
      WHERE src.item_id = main.item_id";
  }

  public function getCopySql(
    string $srcTable,
    string $srcCol,
    string $tgtTable,
    string $tgtCol,
    string $joinType,
    bool $coalesce
  ): string {
    $escSrc = $this->database->escapeField($srcCol);
    $escTgt = $this->database->escapeField($tgtCol);

    if ($this->database->driver() === 'mysql') {
      $setValue = $coalesce ? "COALESCE(s.{$escSrc}, t.{$escTgt})" : "s.{$escSrc}";
      return "UPDATE {{$tgtTable}} t
        {$joinType} JOIN {{$srcTable}} s ON s.item_id = t.item_id
        SET t.{$escTgt} = {$setValue}";
    }

    // Non-MySQL UPDATEs alias the target with "AS t": SQLite rejects a bare
    // alias there.
    if ($joinType === 'LEFT') {
      $srcExpr  = "(SELECT s.{$escSrc} FROM {{$srcTable}} s WHERE s.item_id = t.item_id)";
      $setValue = $coalesce ? "COALESCE({$srcExpr}, {$escTgt})" : $srcExpr;
      return "UPDATE {{$tgtTable}} AS t SET {$escTgt} = {$setValue}";
    }

    $setValue = $coalesce ? "COALESCE(s.{$escSrc}, t.{$escTgt})" : "s.{$escSrc}";
    return "UPDATE {{$tgtTable}} AS t
      SET {$escTgt} = {$setValue}
      FROM {{$srcTable}} s
      WHERE s.item_id = t.item_id";
  }

  protected function buildAggFuncExpr(string $aggFunc, string $valueExpr): string {
    if ($aggFunc !== 'GROUP_CONCAT') {
      return "{$aggFunc}({$valueExpr})";
    }
    switch ($this->database->driver()) {
      case 'mysql':
        return "GROUP_CONCAT({$valueExpr} SEPARATOR ',')";

      case 'pgsql':
        return "STRING_AGG(CAST({$valueExpr} AS TEXT), ',')";

      default:
        return "GROUP_CONCAT({$valueExpr}, ',')";
    }
  }

  public function getFillFromUnionSql(string $tgtTable, string $escTgt, array $unionParts, string $aggFunc, string $joinType): string {
    $union   = implode(' UNION ALL ', $unionParts);
    $aggExpr = $this->buildAggFuncExpr($aggFunc, 'union_src.val');

    if ($this->database->driver() === 'mysql') {
      return "UPDATE {{$tgtTable}} t
        {$joinType} JOIN (
          SELECT item_id, {$aggExpr} AS agg_val
          FROM ( {$union} ) AS union_src
          GROUP BY item_id
        ) AS agg ON agg.item_id = t.item_id
        SET t.{$escTgt} = agg.agg_val";
    }

    if ($joinType === 'LEFT') {
      return "UPDATE {{$tgtTable}} AS t
        SET {$escTgt} = (
          SELECT {$aggExpr} FROM ( {$union} ) AS union_src
          WHERE union_src.item_id = t.item_id
        )";
    }

    return "UPDATE {{$tgtTable}} AS t
      SET {$escTgt} = agg.agg_val
      FROM (
        SELECT item_id, {$aggExpr} AS agg_val
        FROM ( {$union} ) AS union_src
        GROUP BY item_id
      ) AS agg
      WHERE agg.item_id = t.item_id";
  }

  public function getPriorityFillSql(string $tgtTable, string $escTgt, array $sources): string {
    if ($this->database->driver() === 'mysql') {
      $joins        = [];
      $coalesceArgs = [];
      foreach ($sources as $i => $src) {
        $alias         = 'pf' . $i;
        $escSrcCol     = $this->database->escapeField((string) $src['column']);
        $joins[]       = "LEFT JOIN {{$src['table']}} {$alias} ON {$alias}.item_id = t.item_id";
        $coalesceArgs[] = "{$alias}.{$escSrcCol}";
      }
      return "UPDATE {{$tgtTable}} t\n"
        . implode("\n", $joins) . "\n"
        . "SET t.{$escTgt} = COALESCE(" . implode(', ', $coalesceArgs) . ')';
    }

    $coalesceArgs = [];
    foreach ($sources as $src) {
      $escSrcCol      = $this->database->escapeField((string) $src['column']);
      $coalesceArgs[] = "(SELECT {$escSrcCol} FROM {{$src['table']}} WHERE item_id = t.item_id)";
    }
    return "UPDATE {{$tgtTable}} AS t SET {$escTgt} = COALESCE(" . implode(', ', $coalesceArgs) . ')';
  }

}
