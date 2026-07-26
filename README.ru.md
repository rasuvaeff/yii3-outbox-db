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

Регистрируйте поставляемую миграцию **по namespace** — без путей в `vendor/`:

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

Имя таблицы задаётся в params — то же значение получают и миграция, и
`DbOutboxStorage`:

```php
// config/common/params.php
'rasuvaeff/yii3-outbox-db' => [
    'table' => 'my_outbox',
    'table_prefix' => '',   // добавляется перед `table`; например 'rsv_' → rsv_my_outbox
],
```

Имена индексов следуют за именем таблицы (`idx_my_outbox_pending`), поэтому
две инсталляции могут делить одну схему PostgreSQL — там имена индексов
уникальны в пределах схемы, а не таблицы.

> **Не настраивайте миграцию через DI-контейнер.**
> `M...::class => ['__construct()' => ['table' => ...]]` не работает: миграцию
> создаёт `Injector::make()`, который резолвит аргументы по типу и никогда не
> читает определение контейнера по имени класса самой миграции. Хуже того,
> добавление такого определения роняет контейнер на этапе сборки в **каждом**
> запросе, потому что класс не автозагружается, пока его не подключит раннер
> миграций. Этот рецепт был описан в 1.x и никогда не работал.

### Запись и обработка

```php
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;

$storage = new DbOutboxStorage(db: $connection);          // ConnectionInterface
$outbox = new Outbox(storage: $storage, clock: $clock);

// request path — durable, no network call to the sink
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout"}');

// worker — атомарно захватить пачку типов одного потребителя и обработать
$claimed = $storage->claim(types: ['ab.exposure', 'ab.conversion'], limit: 1000);
```

### API хранилища

| Метод | Назначение |
|---|---|
| `save(OutboxMessage)` | upsert по `id` (первичная запись или пересохранение при retry) |
| `claim(array $types = [], int $limit = 1000)` | **то, что вызывает воркер.** Атомарно переводит до `limit` строк из `Pending` в `Processing` и возвращает их, сортировка `created_at` ASC |
| `findPending(array $types = [], int $limit = 1000)` | read-only список строк в статусе `Pending`, с необязательным фильтром по типу, сортировка `created_at` ASC |
| `markPublished(OutboxMessage)` | пересохранить со статусом `Published` |
| `markFailed(OutboxMessage)` | пересохранить со статусом `Failed` |
| `getById(string $id)` | одно сообщение или `null` |
| `deleteByStatus(OutboxStatus)` | очистка (например, удалить всё со статусом `Published`) |

#### `claim()` против `findPending()`

`claim()` — примитив, который обязан использовать воркер, и именно его вызывает
`Processor`. Он работает внутри транзакции: выбирает id ожидающих строк,
проставляет им `Processing` и случайный токен `claimed_by`, затем перечитывает
ровно те строки, что несут этот токен. Два воркера, опрашивающие таблицу
одновременно, никогда не получат одно и то же сообщение.

`findPending()` — обычное чтение. Ничего не блокируется и не помечается, поэтому
два воркера получат одни и те же строки и опубликуют сообщение дважды.
Используйте его для дашбордов, админок и диагностики — но не как выборку воркера.

Каждое захваченное сообщение обязано дойти до терминального состояния:
`markPublished()`, `markFailed()` или `save($message->withStatus(OutboxStatus::Pending))`
для освобождения. Воркер, упавший посреди пачки, оставляет строки в `Processing`;
они останутся там, пока их кто-нибудь не вернёт, — поэтому растущее число
`Processing` стоит считать поводом для алерта.

Фильтр `$types` позволяет нескольким потребителям — универсальному `Processor`
и специализированному экспортёру — совместно использовать один outbox.
Поскольку `claim()` отдаёт каждое сообщение ровно одному вызывающему, их наборы
типов не должны пересекаться: сообщение, подходящее обоим, дойдёт только до
того воркера, который захватил его первым.

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
