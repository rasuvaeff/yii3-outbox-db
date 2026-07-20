# rasuvaeff/yii3-outbox-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Build](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[English version](README.md)

Хранилище сообщений outbox на базе БД для пакета
[`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox). Надёжно
персистит outbox-сообщения в таблице `yiisoft/db`, чтобы воркер мог
асинхронно публиковать или экспортировать их — переживая перезапуски процесса и
сбои на стороне получателя.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-outbox` ^1.0
- `yiisoft/db` ^2.0, `yiisoft/db-migration` ^2.0

## Установка

```bash
composer require rasuvaeff/yii3-outbox-db
```

## Использование

### Миграция

Примените поставляемую миграцию, чтобы создать таблицу `outbox` (имя по умолчанию `outbox`):

```php
use M260611000000CreateOutboxTable;

(new M260611000000CreateOutboxTable())->up($migrationBuilder);
// custom table: new M260611000000CreateOutboxTable(table: 'my_outbox')
```

### Запись и обработка

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

### API хранилища

| Метод | Назначение |
|---|---|
| `save(OutboxMessage)` | upsert по `id` (первичная запись или пересохранение при retry) |
| `findPending(array $types = [], int $limit = 1000)` | строки в статусе `Pending`, с необязательным фильтром по типу, сортировка `created_at` ASC |
| `markPublished(OutboxMessage)` | пересохранить со статусом `Published` |
| `markFailed(OutboxMessage)` | пересохранить со статусом `Failed` |
| `getById(string $id)` | одно сообщение или `null` |
| `deleteByStatus(OutboxStatus)` | очистка (например, удалить всё со статусом `Published`) |

Фильтр `$types` метода `findPending` позволяет нескольким потребителям —
универсальному `Processor` и специализированному экспортёру — совместно
использовать один outbox, не конкурируя за сообщения друг друга.

### Yii3 DI

config-plugin биндит `StorageInterface` на `DbOutboxStorage` из `config/di.php`.
Ядро `yii3-outbox` ничего не биндит, поэтому этот backend (или само приложение)
является единственным источником `StorageInterface`. Имя таблицы задаётся в params:

```php
// config/params.php
'rasuvaeff/yii3-outbox-db' => ['table' => 'outbox'],
```

## Безопасность

- Все значения записываются через параметризованные команды `yiisoft/db`.
- `OutboxRowMapper` валидирует каждую колонку и отбрасывает повреждённые строки
  через `InvalidOutboxRowException` — без молчаливого приведения типов.
- Payload может содержать PII; хранение и очистка — ответственность приложения
  (поможет `deleteByStatus`).

## Примеры

Запускаемые скрипты лежат в [`examples/`](examples/).

## Разработка

```bash
make build        # full gate: validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-coverage
make mutation
```

Ядро `yii3-outbox` подключается через path repository, пока не опубликовано —
см. [AGENTS.md](AGENTS.md) про запуск Docker с монтированием корня монорепо.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
