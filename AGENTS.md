# AGENTS.md — yii3-outbox-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed storage for `rasuvaeff/yii3-outbox`: a `StorageInterface`
implementation over `yiisoft/db`, plus the migration for the `outbox` table.
Namespace: `Rasuvaeff\Yii3OutboxDb`.

Public API:
- `DbOutboxStorage implements StorageInterface` — `save` (upsert by id),
  `findPending(array $types = [], int $limit = 1000)`, `markPublished`,
  `markFailed`, `getById`, `deleteByStatus`.
- `OutboxRowMapper` (`@internal`) — DB row → `OutboxMessage`, with validation.
- `Exception\InvalidOutboxRowException` — thrown on corrupt rows.
- `Migration\M260611000000CreateOutboxTable` — the `outbox` table.

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

No PHP/Composer on the host — run in Docker via the `composer:2` image. Core
`yii3-outbox` is consumed via a path repository while unpublished, so mount the
**monorepo root**, not just this package dir:

```bash
# inject the path repo + install (creates the vendor symlink, drops the lock)
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-outbox-db composer:2 sh -c '
  composer config repositories.core path ../yii3-outbox &&
  composer update -q &&
  composer config --unset repositories.core &&
  rm -f composer.lock'

# build (vendor symlink persists; composer.json stays publish-clean)
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-outbox-db composer:2 composer build
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

`composer.json` keeps `rasuvaeff/yii3-outbox: ^1.0` (Packagist) with **no**
committed `repositories` block, so it is publish-ready. Until core is on
Packagist the GitHub CI of this package is red — expected; the joint release
publishes core first.

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
- SQLite integration tests run in-memory and are covered by `composer build`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` (monorepo-root mount); if the change affects the
  public API or release process, also run `make release-check`. Paste the output.
