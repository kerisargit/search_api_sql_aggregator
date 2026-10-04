# Search API SQL Aggregator

Runs configurable SQL operations (aggregate, copy, null-reset, fill-from-union,
priority-fill, custom SQL) against Search API Database backend tables. Usage
and the security model: the module help page (`/admin/help/search_api_sql_aggregator`).

## Requirements

| | Minimum | Notes |
|---|---|---|
| Drupal | 10.0 (`^10`) | Only APIs stable since 10.0. Drupal 11 is not declared — not checked. |
| PHP | 8.1 (= Drupal 10's own minimum) | Unit and Kernel tests run on 8.1.34 and 8.3.33. |
| Search API | any release compatible with Drupal 10 | The `search_api.items_indexed` event used by the "after indexing" trigger exists since 8.x-1.14. Tested with 8.x-1.41. Database backend (`search_api_db`) is what the module operates on. |
| Interval Trigger | any | Required: runs the cron and page-request schedule (see below). |
| MySQL | 5.7.8 (= core) | Per-run "SQL query timeout" uses `max_execution_time`, which MySQL applies to **SELECT statements only** — UPDATE/INSERT are not interrupted. |
| MariaDB | 10.3.7 (= core) | The timeout uses `max_statement_time` (all statements). Tested on 13.0.1. |
| PostgreSQL | 12 (= core) | `statement_timeout`. Not run against a real server yet — only mocked unit tests. |
| SQLite | **3.33.0** (core accepts 3.26) | copy (INNER) and fill_from_union generate `UPDATE … FROM`, added in SQLite 3.33. Checked by `hook_requirements()` at install and on the status report. Kernel tests run on 3.53.2. |

## Scheduling

Cron and "Also trigger on page requests" run through the
Interval Trigger module as one task
(`AggregationTask`); the schedule itself is `Schedule\AggregationSchedule`
(daily/weekly times in the site time zone). The "after Search API finishes
indexing" trigger is a Search API event subscriber and does not depend on
Interval Trigger.

Upgrading an existing site: deploy the code, then run `drush updatedb` —
`search_api_sql_aggregator_update_10001()` installs Interval Trigger. Until then
no scheduled runs happen, and the status report says so.

## Tests

```
SIMPLETEST_DB=sqlite://localhost/tmp/saa.sqlite vendor/bin/phpunit -c core modules/custom/search_api_sql_aggregator-master/tests/src/Unit
SIMPLETEST_DB=sqlite://localhost/tmp/saa.sqlite vendor/bin/phpunit -c core modules/custom/search_api_sql_aggregator-master/tests/src/Kernel
```
