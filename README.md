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

Apply the bundled migration to create the `outbox` table (default name `outbox`):

```php
use M260611000000CreateOutboxTable;

(new M260611000000CreateOutboxTable())->up($migrationBuilder);
// custom table: new M260611000000CreateOutboxTable(table: 'my_outbox')
```

### Recording and processing

```php
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;

$storage = new DbOutboxStorage(db: $connection);          // ConnectionInterface
$outbox = new Outbox(storage: $storage, clock: $clock);

// request path — durable, no network call to the sink
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout"}');

// worker — fetch a batch of one consumer's types and process them
$pending = $storage->findPending(types: ['ab.exposure', 'ab.conversion'], limit: 1000);
```

### Storage API

| Method | Purpose |
|---|---|
| `save(OutboxMessage)` | upsert by `id` (initial record or retry re-save) |
| `findPending(array $types = [], int $limit = 1000)` | pending rows, optional type filter, `created_at` ASC |
| `markPublished(OutboxMessage)` | re-save with `Published` status |
| `markFailed(OutboxMessage)` | re-save with `Failed` status |
| `getById(string $id)` | single message or `null` |
| `deleteByStatus(OutboxStatus)` | housekeeping (e.g. purge `Published`) |

`findPending`'s `$types` filter lets several consumers — a generic `Processor`
and a specialized exporter — share one outbox without competing for each other's
messages.

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
