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
- `rasuvaeff/yii3-outbox` ^1.6
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

`yiisoft/db-migration` строит миграцию через `Injector::make()`, поэтому она
получает value object имени таблицы из контейнера так же, как и хранилище —
никакой ручной проводки сверх `setSourceNamespaces()` выше не нужно.

Имя таблицы задаётся в params — то же значение получают и миграция, и
`DbOutboxStorage`:

```php
// config/common/params.php
'rasuvaeff/yii3-outbox-db' => [
    'table' => 'my_outbox',
    'table_prefix' => '',   // добавляется перед `table`; например 'rsv_' → rsv_my_outbox
],
```

Имена индексов следуют за именем таблицы (`idx_my_outbox_pending`,
`idx_my_outbox_processing`), поэтому две инсталляции могут делить одну схему
PostgreSQL — там имена индексов уникальны в пределах схемы, а не таблицы.

Миграции, по порядку:

| Миграция | Что делает |
|---|---|
| `M260611000000CreateOutboxTable` | создаёт таблицу и индекс для pending-выборки |
| `M260820000000AddOutboxClaimedAt` | добавляет `claimed_at` и индекс для processing-выборки — для восстановления зависших claim'ов |

`M260820000000AddOutboxClaimedAt::down()` работает только на MySQL и
PostgreSQL — `yiisoft/db-sqlite` не умеет удалять колонку.

#### Размер payload

`payload` — это `TEXT`. PostgreSQL и SQLite считают его безразмерным, **MySQL
ограничивает его 65 535 байтами**. Для доменного события этого потолка с
запасом хватает — payload в outbox должен нести ссылку, а не блоб, — поэтому
схема не заставляет каждую MySQL-инсталляцию перестраивать таблицу ради его
поднятия. Если событиям действительно нужно больше, расширьте колонку сами,
один раз:

```sql
-- Подставьте свою таблицу: `table_prefix` + `table` из params, по умолчанию
-- `outbox`.
ALTER TABLE outbox MODIFY payload MEDIUMTEXT NOT NULL;
```

Стоит понимать, чем оборачивается этот лимит. В strict-режиме MySQL (дефолт с
5.7) вставка падает — а так как `Outbox::record()` выполняется внутри вашей
бизнес-транзакции, вместе с ней откатывается и бизнес-запись. В нестрогом
режиме payload молча обрезается, и сообщение публикуется с тем, что уцелело:
JSON почти наверняка окажется нечитаемым, а если он всё же распарсится — это
хуже, потому что консюмер примет испорченное событие, ничего не заметив.

> **Точка входа в DI — `MigrationService`, а не класс миграции.**
> Регистрация namespace через `MigrationService::setSourceNamespaces()`, как
> выше, — поддерживаемый рецепт. А определение по самой миграции —
> `M...::class => ['__construct()' => ['table' => ...]]` — не даёт эффекта:
> миграцию создаёт `Injector::make()`, который резолвит аргументы конструктора
> по типу из контейнера и никогда не читает определение контейнера по имени
> создаваемого класса. Задавайте таблицу в params — собранный из них
> `OutboxTableName` и есть то, что `Injector` резолвит по типу, одинаково для
> миграции и для хранилища.

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
| `claimReady(DateTimeImmutable $readyThreshold, int $maxAttempts, array $types = [], int $limit = 1000)` | тот же захват без строк, ещё ждущих окончания backoff. Именно это вызывает `Processor` |
| `findPending(array $types = [], int $limit = 1000)` | read-only список строк в статусе `Pending`, с необязательным фильтром по типу, сортировка `created_at` ASC |
| `markPublished(OutboxMessage)` | пересохранить со статусом `Published` — или удалить строку при `deletePublished: true` |
| `markPublishedBatch(list<OutboxMessage>)` | то же для целого батча одним statement'ом (`BatchAcknowledgingStorageInterface`); это вызывает `yii3-outbox-clickhouse` |
| `markFailed(OutboxMessage)` | пересохранить со статусом `Failed` |
| `getById(string $id)` | одно сообщение или `null` |
| `deleteByStatus(OutboxStatus)` | очистка (например, удалить всё со статусом `Published`); не нужна при `deletePublished: true` |
| `findStaleClaims(DateTimeImmutable $claimedBefore, int $limit = 1000)` | строки, всё ещё `Processing`, чей claim старше порога |
| `releaseStaleClaims(DateTimeImmutable $claimedBefore, int $limit = 1000)` | возвращает такие строки в `Pending`; отдаёт количество |

#### Backoff больше не доходит до PHP

`DbOutboxStorage` реализует `Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface`,
поэтому `Processor` захватывает через `claimReady()`, и сообщение, у которого не
истекла задержка повтора, из таблицы вообще не забирается. Раньше захватывались
любые `Pending`-строки, а ядро тут же писало неготовые обратно — две записи на
каждое отложенное сообщение за итерацию, и каждое занимало слот в `batchSize`,
который мог достаться готовому.

Дополнительное условие — одно:

```sql
AND (attempts >= :maxAttempts
     OR last_attempt_at IS NULL
     OR last_attempt_at <= :readyThreshold)
```

`attempts >= :maxAttempts` — не оптимизация. Сообщение с исчерпанными попытками
можно только пометить `Failed`, а `Processor` способен пометить лишь то, что
хранилище ему отдало: отфильтруйте его — и завершить его не сможет никто, оно
навсегда останется `Pending`, невидимое для алерта на `Failed`.

Ни миграции, ни нового индекса это не требует. `idx_<table>_pending`
(`status`, `type`, `created_at`) по-прежнему сужает скан и обслуживает
сортировку; добавленная дизъюнкция — это `OR` по двум колонкам, который целиком
не покрывается ни одним индексом, и вычисляется он на строках, уже отобранных
существующим индексом.

Для эксплуатации меняются две вещи:

- `ProcessingResult::$skipped` теперь `0` — сообщения, которые он считал, больше
  не захватываются. Чтобы знать, сколько ждёт повтора, считайте `Pending`-строки
  со свежим `last_attempt_at`.
- Сообщение с исчерпанными попытками помечается `Failed` на величину до
  `delaySeconds` позже: оно ждёт батча, в который попадёт.

Требует `rasuvaeff/yii3-outbox` ^1.5.

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
для освобождения.

#### Восстановление зависших claim'ов

Воркер, убитый между `claim()` и финализирующей записью — SIGKILL под
supervisor или k8s, OOM, таймаут демона, — оставляет свои строки в
`Processing`, и никакая логика повторов их сама не вернёт. `claim()` проставляет
`claimed_at`, поэтому брошенный claim отличим от свежего:

```php
$threshold = $clock->now()->modify('-15 minutes');

// Сначала посмотреть — это же и должен отдавать мониторинг.
$stuck = $storage->findStaleClaims($threshold);

// Затем вернуть; они уходят в Pending, не потратив попытку.
$released = $storage->releaseStaleClaims($threshold);
```

Запускайте освобождение из cron или хука supervisor'а с порогом, заведомо
большим самой долгой пачки: освободить claim, который ещё держит живой воркер,
значит доставить сообщение дважды — контракт at-least-once это допускает, но
радости мало. Строка в `Processing` без отметки времени считается зависшей —
и та, что осталась от версии старее колонки, и та, что записана через `save()`,
который всегда чистит `claimed_by` вместе с `claimed_at`. Ни одна из них не
удерживается живым claim'ом — ровно об этом и говорит пустой `claimed_by`.

Растущее число `Processing` по-прежнему повод для алерта — но теперь у него
есть и лекарство.

Фильтр `$types` позволяет нескольким потребителям — универсальному `Processor`
и специализированному экспортёру — совместно использовать один outbox.
Поскольку `claim()` отдаёт каждое сообщение ровно одному вызывающему, их наборы
типов не должны пересекаться: сообщение, подходящее обоим, дойдёт только до
того воркера, который захватил его первым.

#### Подтверждение батча и судьба отправленного

`markPublished()` — один upsert на сообщение. Потребитель, доставляющий батч
целиком — `yii3-outbox-clickhouse` пишет один bulk insert на группу, — раньше
подтверждал группу из тысячи сообщений тысячей statements в OLTP-базе.
`DbOutboxStorage` реализует `BatchAcknowledgingStorageInterface` из ядра, и
такой потребитель подтверждает всю группу через `markPublishedBatch()`: один
`UPDATE … WHERE id IN (…)` на каждый различный штамп попытки, то есть для
группы, подтверждаемой вместе, — один statement. Каждая строка оказывается
ровно в том виде, в каком её оставил бы `markPublished()`.

Что происходит с подтверждённой строкой — флаг конструктора:

```php
new DbOutboxStorage(db: $connection, deletePublished: true);
// или через config-plugin:
'rasuvaeff/yii3-outbox-db' => ['delete_published' => true],
```

| `deletePublished` | Подтверждённая строка | Очистка |
|---|---|---|
| `false` (по умолчанию) | остаётся как `Published` | `deleteByStatus(OutboxStatus::Published)` из cron |
| `true` | удаляется | не нужна: в таблице только `Pending`/`Processing`/`Failed`, pending-индекс остаётся маленьким |

Флаг действует и на `markPublished()`, поэтому `Processor` и батчевый
экспортёр, делящие одну таблицу, сходятся в том, что в ней лежит. Цена `true` —
аудит отправленного: наблюдайте его на стороне sink (id события в ClickHouse —
это id outbox-строки). At-least-once не меняется в обоих режимах: падение между
записью в sink и подтверждением оставляет строку в `Processing`, освобождение
зависших claim'ов возвращает её в `Pending`, она доставляется повторно и
дедуплицируется ниже по потоку.

Guard `status = 'processing'` в подтверждении намеренно отсутствует. Оно
выполняется после успешной доставки, а строка неизменяема кроме статуса,
поэтому подтвердить её правильно в любом состоянии: строку, которую cron
освободил и второй воркер заново захватил, батч первого воркера подтверждает
всё равно, а подтверждению второго воркера остаётся ничего не делать. Guard
лишь оставил бы освобождённую строку на месте — гарантированная вторая
доставка.

### Yii3 DI

config-plugin биндит `StorageInterface` на `DbOutboxStorage` из `config/di.php`.
Ядро `yii3-outbox` ничего не биндит, поэтому этот backend (или само приложение)
является единственным источником `StorageInterface`. Имя таблицы задаётся в params:

```php
// config/params.php
'rasuvaeff/yii3-outbox-db' => [
    'table' => 'outbox',
    'delete_published' => false,   // true: подтверждённые строки удаляются, cron-очистка не нужна
],
```

## Безопасность

- Все значения записываются через параметризованные команды `yiisoft/db`.
- `OutboxRowMapper` валидирует каждую колонку и отбрасывает повреждённые строки
  через `InvalidOutboxRowException` — без молчаливого приведения типов.
- Payload может содержать PII; хранение и очистка — ответственность приложения
  (поможет `deleteByStatus`; `deletePublished: true` удаляет строку в момент
  подтверждения).

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
