# Changelog

## Unreleased

- Docs: the documented `setSourceNamespaces()` migration registration does not
  find the bundled migration and never has — `yiisoft/db-migration` matches the
  PSR-4 map by string prefix and resolves into the core package, so
  `./yii migrate:up` exits 0 having created nothing. Both READMEs now say so and
  give a working `Injector`-based recipe until the upstream fix ships.

## 2.0.1 — 2026-07-26

- Document `claim()`. It is the atomic primitive a worker must use — and the one
  `Processor` calls — but the storage API tables in `README.md`, `README.ru.md`
  and `llms.txt` omitted it entirely, and the worker example showed
  `findPending()` instead. Following the docs gave non-atomic polling: two
  workers pick up the same row and publish it twice.
- Spell out the operational consequences: every claimed message must reach a
  terminal state or it stays `Processing`, and independent consumers sharing one
  outbox must not overlap in their `types` sets.

## 2.0.0 — 2026-07-25

**Breaking.** See [UPGRADE.md](UPGRADE.md) — an installation that already
applied the migration must rewrite one row in the `migration` table.

- The bundled migration moved to `Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable`
  (`src/Migration/`, PSR-4 autoloaded) from a global class in `migrations/`.
  Register it with `setSourceNamespaces()` instead of a `vendor/` path. Being
  autoloadable is what makes it safe to reference in DI at all: with the old
  global class, adding any container definition for it made
  `Yiisoft\Di\Container` fatal at build time in every request, because
  `new ReflectionClass()` ran before the migration runner had required the file.
- **The documented way to rename the table never worked.**
  `M...::class => ['__construct()' => ['table' => ...]]` is ignored:
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or type from the container and does not read
  definitions keyed by the migration's class — and a scalar `string $table` has
  no type to resolve. Users following the README silently got the default name.
- The table name is now a typed value object that `Injector` *can* resolve,
  built by `config/di.php` from params. One source of truth: the migration and
  `DbOutboxStorage` cannot disagree any more (in 1.x the runtime read params while the
  migration used its own default, so configuring params pointed the runtime at a
  table the migration had never created).
- New `table_prefix` param, prepended to `table` — a single place to keep
  package tables out of the way of an application's own.
- Index names are derived from the table name (`idx_<table>_pending`).
  Unchanged for the default table name; in PostgreSQL, where index names are
  unique per schema rather than per table, a hard-coded name collided between
  two installations sharing a schema.
- `DbOutboxStorage` validates the table name (through the same value object) —
  in 1.x it interpolated whatever string it was given straight into the query
  builder, with no identifier check at all.
- The row mapper's integer check is anchored with `\z` instead of `$`: PCRE's
  `$` also matches before a trailing newline.
- Fix `examples/basic-usage.php`: it created the table with a hand-written
  `CREATE TABLE` that had drifted from the migration (no `claimed_by` column),
  so running it fatalled on the first `claim()`. It now applies the bundled
  migration, which cannot drift.


## 1.0.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- `DbOutboxStorage` — `StorageInterface` backed by `yiisoft/db`: `save` (upsert by id), `findPending(array $types, int $limit)` (status + optional type filter, ordered by `created_at`), `markPublished`, `markFailed`, `getById`, plus `deleteByStatus` for housekeeping.
- `OutboxRowMapper` — maps DB rows to `OutboxMessage`, validating status, datetimes and integer columns; throws `InvalidOutboxRowException` on corrupt rows.
- `migrations/M260611000000CreateOutboxTable` — `outbox` table (MergeTree-agnostic SQL) with the `idx_outbox_status_type` index backing the pending poll.
- Yii3 config-plugin: binds `StorageInterface` from `config/di.php`; table name in `config/params.php`.

