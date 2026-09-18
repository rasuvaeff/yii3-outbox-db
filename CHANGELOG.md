# Changelog

## 2.5.0 — 2026-09-18

### Added

- `skipLocked: true` (params `skip_locked`): the claim's id select runs
  `FOR UPDATE SKIP LOCKED`, so concurrent workers take disjoint rows at once
  instead of waiting on each other's row locks. MySQL 8+ and PostgreSQL 9.5+
  only — SQLite has no `FOR` clause and rejects the claim with
  `NotSupportedException`. Verified on both engines by
  `CrossDatabaseMigrationTest`: a claim skips a row another transaction holds
  locked and takes it once the lock is gone (#33).

## 2.4.0 — 2026-09-18

### Added

- `DbOutboxStorage` implements the three optional capabilities of
  `rasuvaeff/yii3-outbox` 1.7: `BatchSavingStorageInterface::saveBatch()` as
  one multi-row `INSERT` (new rows only, no upsert),
  `RequeueableStorageInterface::findFailed()` / `requeue()` — the latter one
  `UPDATE ... WHERE id = ? AND status = 'failed'`, so the row's current
  status decides — and `StatsAwareStorageInterface::stats()` as one
  `GROUP BY status` query. The core constraint is `^1.7` (#32).
- `deleteByStatus()` takes an optional `$olderThan`: a `Published` purge can
  keep the recent rows an audit or a deduplication check still wants (#30).
- Three console commands, registered for `yiisoft/yii-console` in
  `config/params.php` (`symfony/console` is now a dependency):
  `outbox:purge [--status=published|failed] [--older-than=7d]`,
  `outbox:release-stale [--claimed-before=15m] [--limit=1000]` and
  `outbox:requeue [--type=T]... [--limit=1000]`. Ages are `<int><s|m|h|d>`;
  a malformed value exits `Command::INVALID` without touching a row (#30).
- `requireTransaction: true` (params `require_transaction`): a `save()` that
  would create a new row, or a `saveBatch()`, throws
  `Exception\OutboxWriteOutsideTransactionException` when no transaction is
  open on the connection — the cheap way to catch `Outbox::record()` placed
  outside the business transaction, in development and CI. Worker re-saves of
  existing rows pass; the mode costs one existence check per
  non-transactional `save()` (#31).
- `CrossDatabaseMigrationTest` applies both migrations up and down and drives
  the storage's engine-sensitive writes (`upsert`, `insertBatch`, the token
  claim, `stats()`) on MySQL 8.4 and PostgreSQL 17; the ungated
  `database-integration` matrix job in CI supplies both (#28).

### Fixed

- README: `yiisoft/db-migration` requirement is `^2.1`, not `^2.0` — 2.1.0 is
  where `setSourceNamespaces()` finds a vendor migration at all; the
  readiness-pushdown section claimed core `^1.5` while the package requires
  `^1.6`; the two params examples disagreed on the config path (#29).
- `release.yml` verifies that the tag points at validated `master` history
  before publishing a GitHub Release (template of 2026-08-22).
- `composer.json` declares `extra.branch-alias` (`dev-master` → `2.x-dev`) so
  the family's config-merge harness can resolve the package from a path
  repository (rasuvaeff/yii3-outbox#32).

## 2.3.0 — 2026-09-16

### Added

- `DbOutboxStorage` implements `BatchAcknowledgingStorageInterface` (new in
  `rasuvaeff/yii3-outbox` 1.6.0): `markPublishedBatch()` acknowledges a whole
  batch with one `UPDATE … WHERE id IN (…)` per distinct attempt stamp instead
  of one upsert per message
  ([#26](https://github.com/rasuvaeff/yii3-outbox-db/issues/26)).
- Constructor flag `deletePublished` (params key `delete_published`, default
  `false`): with `true`, `markPublished()` and `markPublishedBatch()` delete
  the acknowledged rows instead of keeping them as `Published`, so no
  `deleteByStatus(Published)` purge is needed and the table holds only
  `Pending`/`Processing`/`Failed` rows.

### Changed

- Requires `rasuvaeff/yii3-outbox` `^1.6`.
- `ConfigWiringTest` no longer imitates the cross-package merge with
  `array_intersect_key()`; that check is `bin/config-merge-harness @outbox`
  in the monorepo.

## 2.2.0 — 2026-08-20

### Added

- `DbOutboxStorage` implements `RetryAwareStorageInterface` (new in
  `rasuvaeff/yii3-outbox` 1.5.0), so `Processor` claims through `claimReady()`
  and a message whose retry delay has not elapsed is never taken from the table
  ([#22](https://github.com/rasuvaeff/yii3-outbox-db/issues/22)).

  Every `Pending` row used to be claimed and the core wrote the not-yet-due ones
  straight back — two writes per backing-off message per worker iteration, plus
  the row locks, and each one occupied a slot in `batchSize` that a ready
  message could have used, so delivery latency grew with the size of the retry
  queue. The predicate is now one `AND` clause in the claim query.

  No migration and no new index: `idx_<table>_pending` still narrows the scan
  and serves the ordering, and the added disjunction is an `OR` across two
  columns that no index satisfies as a whole.

- `composer test:integration`, and a CI step that runs it on every PHP version
  in the matrix. The Integration suite did already execute in CI, but only
  incidentally: `composer mutation` runs every suite, so a broken query surfaced
  as Infection failing to complete its initial test run, in the one job that
  also needs `pcov`, on one PHP version. `composer build` never touched it, so
  a version-specific SQL failure on 8.3 or 8.5 had nothing to catch it.

### Changed

- Requires `rasuvaeff/yii3-outbox` ^1.5 (was ^1.0): `RetryAwareStorageInterface`
  does not exist below it.
- `ProcessingResult::$skipped` reads `0` against this storage — the messages it
  counted are no longer claimed. Count `Pending` rows with a recent
  `last_attempt_at` to see how many are backing off.
- A message that has spent its attempts is marked `Failed` up to `delaySeconds`
  later than before, since it now waits for a batch that includes it.

## 2.1.0 — 2026-08-20

**Required migration.** `claim()` writes the new `claimed_at` column, so
`M260820000000AddOutboxClaimedAt` must run **before** the new code is deployed.
See [`UPGRADE.md`](UPGRADE.md) — it also explains what the first
`releaseStaleClaims()` call does to rows stuck from before the upgrade.

### Added

- `DbOutboxStorage::findStaleClaims()` and `releaseStaleClaims()`, plus the
  `claimed_at` column they need (migration
  `M260820000000AddOutboxClaimedAt`). A worker killed between `claim()` and the
  finalising write left its rows in `Processing` with no way out: `claimed_by`
  alone cannot tell a fresh claim from an abandoned one, and the API offered no
  way to find or release them, so the operator wrote SQL by hand in production
  ([#19](https://github.com/rasuvaeff/yii3-outbox-db/issues/19)).
- A property over `OutboxRowMapper`: every message the storage writes maps back
  to itself, for any id, type, payload, status, attempt count, aggregate id and
  datetime (including non-UTC input).
- An integration test claiming from two connections to one database — the
  conditional `UPDATE` that makes `claim()` safe for concurrent workers had no
  regression test, because a single connection cannot tell it from a plain
  `SELECT` + `UPDATE`.

### Changed

- `DbOutboxStorage` accepts an optional `Psr\Clock\ClockInterface`, used to
  stamp `claimed_at`. Without one it reads the system clock in UTC.
- `releaseStaleClaims()` repeats the staleness predicate in its `UPDATE`, not
  only in the `SELECT` that picks the ids. The two statements leave a window in
  which a concurrent recovery can release a row and a worker can claim it again;
  updating on the id list alone reset that live claim to `Pending` and handed
  the message to a second worker mid-delivery.
- Both migrations are `final readonly class`, as the package's own style rule
  requires.
- Document the MySQL `TEXT` ceiling on `payload` (65,535 bytes; unbounded on
  PostgreSQL and SQLite), what happens when a payload exceeds it — a failed
  insert that rolls back the business transaction under strict mode, a silent
  truncation that publishes a corrupted payload otherwise — and the one-line
  `ALTER` for installations that need more. Deliberately not a
  migration: 64 KB is generous for a domain event, and widening the column
  would force a table rebuild on every MySQL installation for a case most never
  hit ([#20](https://github.com/rasuvaeff/yii3-outbox-db/issues/20)).
- Correct the migration/DI warning in both READMEs: configuring the migration
  through a container definition keyed by its own class still has no effect
  (`Injector::make()` resolves by type), but it no longer breaks container
  build — migrations have lived under the package's PSR-4 namespace since 2.0.0,
  so the class autoloads like any other.
- Raise the Infection gate from `minMsi` 85 to 90 (the suite scores 93.6%).
- Development tooling: `rasuvaeff/rector-named-literals` in the rector set,
  `rasuvaeff/property-testing-testo` added, a narrow change filter for the
  mutation job, and a cached property regression corpus.

## 2.0.3 — 2026-08-04

### Fixed

- Require `yiisoft/db-migration` ^2.1, which fixes `setSourceNamespaces()` matching a sibling namespace as a parent (upstream [yiisoft/db-migration#350](https://github.com/yiisoft/db-migration/pull/350)). Drop the manual `Injector::make()` migration workaround from both READMEs.

## 2.0.2 — 2026-08-01

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

