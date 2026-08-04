# rasuvaeff/yii3-outbox-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Build](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[Русская версия](README.ru.md)

Database-backed storage for [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox).
Durably persists outbox messages in a `yiisoft/db` table so a worker can publish
or export them asynchronously — surviving process restarts and downstream outages.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-outbox` ^1.0
- `yiisoft/db` ^2.0, `yiisoft/db-migration` ^2.0

## Installation

```bash
composer require rasuvaeff/yii3-outbox-db
```

## Usage

### Migration

Register the bundled migration **by namespace** — no vendor paths:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [[
            'App\\Migration',
            'Rasuvaeff\\Yii3OutboxDb\\Migration',
        ]],
    ],
];
```

```bash
./yii migrate:up
```

`yiisoft/db-migration` resolves the migration through `Injector::make()`, so
it picks up the table-name value object from the container the same way the
storage does — no manual wiring needed beyond `setSourceNamespaces()` above.

Set the table name in params — the same value reaches the migration **and**
`DbOutboxStorage`:

```php
// config/common/params.php
'rasuvaeff/yii3-outbox-db' => [
    'table' => 'my_outbox',
    'table_prefix' => '',   // prepended to `table`; e.g. 'rsv_' → rsv_my_outbox
],
```

Index names follow the table name (`idx_my_outbox_pending`), so two
installations can share one PostgreSQL schema — index names are unique per
schema there, not per table.

> **Do not configure the migration through the DI container.**
> `M...::class => ['__construct()' => ['table' => ...]]` does not work: the
> migration is built by `Injector::make()`, which resolves arguments by type
> and never reads a container definition keyed by the migration's own class.
> Worse, adding that definition makes the container fatal at build time in
> **every** request, because the class is not autoloadable until the migration
> runner requires it. That recipe was documented in 1.x; it never worked.

### Recording and processing

```php
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;

$storage = new DbOutboxStorage(db: $connection);          // ConnectionInterface
$outbox = new Outbox(storage: $storage, clock: $clock);

// request path — durable, no network call to the sink
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout"}');

// worker — atomically claim a batch of one consumer's types and process them
$claimed = $storage->claim(types: ['ab.exposure', 'ab.conversion'], limit: 1000);
```

### Storage API

| Method | Purpose |
|---|---|
| `save(OutboxMessage)` | upsert by `id` (initial record or retry re-save) |
| `claim(array $types = [], int $limit = 1000)` | **what a worker calls.** Atomically flips up to `limit` `Pending` rows to `Processing` and returns them, `created_at` ASC |
| `findPending(array $types = [], int $limit = 1000)` | read-only listing of pending rows, optional type filter, `created_at` ASC |
| `markPublished(OutboxMessage)` | re-save with `Published` status |
| `markFailed(OutboxMessage)` | re-save with `Failed` status |
| `getById(string $id)` | single message or `null` |
| `deleteByStatus(OutboxStatus)` | housekeeping (e.g. purge `Published`) |

#### `claim()` vs `findPending()`

`claim()` is the primitive a worker must use, and the one `Processor` calls.
It runs inside a transaction: it selects the pending ids, stamps them
`Processing` with a random `claimed_by` token, then re-reads exactly the rows
carrying that token. Two workers polling concurrently therefore never receive
the same message.

`findPending()` is a plain read. Nothing is locked or marked, so two workers
polling it both get the same rows and publish the same message twice. Use it
for dashboards, admin screens and diagnostics — never as a worker's fetch.

Every claimed message must reach a terminal state: `markPublished()`,
`markFailed()`, or `save($message->withStatus(OutboxStatus::Pending))` to
release it. A worker that crashes mid-batch leaves rows in `Processing`; they
stay there until something puts them back, so treat a growing `Processing`
count as an alert.

The `$types` filter lets several consumers — a generic `Processor` and a
specialized exporter — share one outbox. Because `claim()` hands each message
to exactly one caller, their type sets must not overlap: a message matching
both is delivered only to whichever worker claimed it first.

### Yii3 DI

The config-plugin binds `StorageInterface` to `DbOutboxStorage` from
`config/di.php`. Core `yii3-outbox` binds nothing, so this backend (or the
application) is the single source of `StorageInterface`. Set the table name in
params:

```php
// config/params.php
'rasuvaeff/yii3-outbox-db' => ['table' => 'outbox'],
```

## Security

- All values are written through `yiisoft/db` parameterized commands.
- `OutboxRowMapper` validates every column and rejects corrupt rows with
  `InvalidOutboxRowException` — no silent coercion.
- Payloads may contain PII; retention/purging is the application's responsibility
  (`deleteByStatus` helps).

## Examples

Runnable scripts live in [`examples/`](examples/).

## Development

```bash
make build        # full gate: validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-coverage
make mutation
```

Core `yii3-outbox` is consumed via a path repository while unpublished — see
[AGENTS.md](AGENTS.md) for the monorepo-root Docker invocation.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
