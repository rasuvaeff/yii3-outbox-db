# AGENTS.md — yii3-outbox-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed storage for `rasuvaeff/yii3-outbox`: a `StorageInterface`
implementation over `yiisoft/db`, plus the migration for the `outbox` table.
Namespace: `Rasuvaeff\Yii3OutboxDb`.

Public API:
- `DbOutboxStorage implements StorageInterface` — `save` (upsert by id),
  `claim`, `findPending(array $types = [], int $limit = 1000)`,
  `markPublished`, `markFailed`, `getById`, `deleteByStatus`,
  `findStaleClaims`, `releaseStaleClaims`.
- `OutboxRowMapper` (`@internal`) — DB row → `OutboxMessage`, with validation.
- `Exception\InvalidOutboxRowException` — thrown on corrupt rows.
- `Migration\M260611000000CreateOutboxTable` — the `outbox` table.
- `Migration\M260820000000AddOutboxClaimedAt` — `claimed_at` + the processing
  index, which stale-claim recovery needs.

The core contracts (`Outbox`, `OutboxMessage`, `StorageInterface`, `RetryPolicy`,
`Processor`) live in `rasuvaeff/yii3-outbox`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **`OutboxMessage::getId()` is the durable identity.** `save` upserts by it;
   the id is the at-least-once dedup anchor downstream consumers (e.g.
   `yii3-outbox-clickhouse`) carry into their sink. Never regenerate or mutate it
   on persistence.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **The table name is a VO, not a string, because `Injector` cannot resolve a
  scalar.** `yiisoft/db-migration` builds migrations via `Injector::make()`,
  which resolves arguments by name or by type from the container and never reads
  a container definition keyed by the migration's own class. That is why the
  1.x recipe `M...::class => ['__construct()' => ['table' => …]]` silently did
  nothing — and why adding it made `Yiisoft\Di\Container` fatal at build time
  (the global class was not autoloadable until the runner required the file).
  Never reintroduce a scalar `string $table` on a migration.
- **One source of truth for the name.** `config/di.php` builds `OutboxTableName`
  from `table_prefix` + `table` params and passes it to both the storage and the
  migration.
- **Index names are derived from the table name** (`idx_<table>_pending`), with
  `.` flattened to `_`. In PostgreSQL index names are unique per schema, not per
  table.
- Migrations live in `src/Migration/` and are therefore covered by cs, psalm and
  infection like any other source file. `MigrationTableNameTest` asserts the
  column set and the index's columns — without it, `ArrayItemRemoval` mutants in
  `createTable`/`createIndex` escape and the MSI gate fails.
- `setSourceNamespaces()` registration works as of `yiisoft/db-migration` ^2.1.
  Earlier releases (≤ 2.0.1) matched the PSR-4 map by string prefix, so
  `Rasuvaeff\Yii3OutboxDb\Migration` resolved into the core package and
  discovery silently found zero — see the README.
- `composer test` runs only the Unit suite; `composer mutation` runs every
  suite. An integration test left pointing at `migrations/` passes the first and
  fails the second.
- Identifier patterns are anchored with `\z`, not `$`: PCRE's `$` also matches
  before a trailing newline.
- `save` upserts by `id` (insert or update) — used both for the initial record
  and for retry re-saves with incremented attempts.
- `markPublished`/`markFailed` re-save the message with the new status, so the
  attempt count and `last_attempt_at` carried by the passed message are
  persisted (mirrors `InMemoryStorage`).
- `findPending` returns `Pending` rows ordered by `created_at` ASC (FIFO),
  filtered by `$types` when non-empty; `RetryPolicy` (in core) decides which are
  ready for retry.
- Datetimes are stored as `Y-m-d H:i:s` strings normalized to UTC.
- `OutboxRowMapper` rejects corrupt rows with `InvalidOutboxRowException` —
  never silently coerce bad data.
- **`claimReady()` must keep taking exhausted messages.** The readiness
  predicate exists to stop claiming rows that are still in backoff, but the
  `attempts >= :maxAttempts` disjunct is not part of that optimisation and must
  not be "simplified" away. A message out of attempts can only ever be marked
  `Failed`, and `Processor` can only fail a message the claim returned — drop
  the clause and those rows stay `Pending` forever, with no alert on `Failed`
  ever firing. Pinned by `claimReadyTakesExhaustedMessagesInsideTheBackoffWindow`
  in the Integration suite.
- **`$readyThreshold` comes from the core.** It is
  `RetryPolicy::readyThreshold($now)`; never rebuild it here from
  `delaySeconds`. The core is the only place that knows the policy, and the
  equivalence between the threshold filter and `isReadyForRetry()` is pinned by
  a property test there, not here.
- **`tests/Integration` is the only thing that covers the SQL.** `composer
  build` runs the Unit suite alone — `src/DbOutboxStorage.php` has no unit test
  at all. The atomic claim, the readiness predicate, stale-claim recovery and
  the two-connection concurrency guarantee live there and nowhere else, so
  adding a query without a test there means adding untested SQL, whatever
  `composer build` says. It is also why Infection scores this package: it runs
  every suite, and `SqliteIntegrationTest` carries
  `#[Covers(DbOutboxStorage::class)]` — remove that attribute and every mutant
  in the storage becomes uncovered. `build.yml` runs the suite explicitly
  (`composer test:integration`) on each matrix PHP version, rather than leaving
  it to the mutation job's initial test run.
- **`claim()` stamps `claimed_at`, and `save()` clears it along with
  `claimed_by`.** Without the timestamp a claim abandoned by a killed worker is
  indistinguishable from a live one, and `findStaleClaims()`/
  `releaseStaleClaims()` have nothing to filter on. A NULL `claimed_at` on a
  `Processing` row counts as stale. It comes from a version predating the
  column, or from `save()` being handed a `Processing` message — `save()` clears
  both columns unconditionally. Neither row is held by a live claim, which is
  what the NULL `claimed_by` beside it says, so releasing both is correct.
- **`releaseStaleClaims()` repeats the staleness predicate in its `UPDATE`, and
  Infection reports both guards as escaped mutants. Do not delete them.** The
  method selects ids and then updates them: two statements, and under MySQL or
  PostgreSQL a concurrent transaction commits in between. Without
  `status = 'processing'` a row published in that window would be resurrected to
  `Pending`; without the `claimed_at` predicate a row released and re-claimed in
  that window would have its live claim reset, handing the message to a second
  worker mid-delivery. Neither can be reproduced in the SQLite suite — SQLite
  serialises the interleaving away — so the mutants survive honestly rather than
  being killed by a test that proves nothing.
- **`payload` stays `TEXT` on purpose.** Unbounded on PostgreSQL and SQLite,
  65,535 bytes on MySQL. Widening it to `MEDIUMTEXT` in a migration would force
  a table rebuild on every MySQL installation for a ceiling most never reach, so
  the limit is documented in both READMEs with a one-line `ALTER` instead. Do
  not "fix" this with a migration without a concrete report of someone hitting
  it.
- A SQLite integration test that needs **two connections** runs against a temp
  file, not `:memory:` — two connections to `:memory:` are two different
  databases, and the concurrent-claim test needs both workers on the same rows.
  Single-connection suites (`MigrationTest`, `ConfigWiringTest`) stay on
  `:memory:`: it is faster and has nothing to clean up.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` (monorepo-root mount); if the change affects the
  public API or release process, also run `make release-check`. Paste the output.
