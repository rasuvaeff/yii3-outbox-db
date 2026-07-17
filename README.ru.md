# rasuvaeff/yii3-outbox-db
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
[![Build](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-db/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-db)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-db/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-db)
Database-backed storage for [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox).
Исходящие сообщения надежно сохраняются в таблице `yiisoft/db`, чтобы работник мог публиковать
 или асинхронно экспортировать их, выдерживая перезапуски процессов и сбои в работе последующих версий.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать. @@ЛИНИЯ@@
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
Примените комплексную миграцию, чтобы создать таблицу «исходящие» (имя по умолчанию «исходящие»):

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
| Метод | Цель |
 |---|---|
 | `сохранить(OutboxMessage)` | upsert по `id` (начальная запись или повторная попытка пересохранения) |
 | `findPending(array $types = [], int $limit = 1000)` | ожидающие строки, дополнительный фильтр типа, `create_at` ASC |
 | `markPublished(OutboxMessage)` | пересохранить со статусом «Опубликовано» |
 | `markFailed(OutboxMessage)` | повторно сохранить со статусом «Не удалось» |
 | `getById(строка $id)` | одно сообщение или `null` |
 | `deleteByStatus(OutboxStatus)` | ведение домашнего хозяйства (например, очистка `Опубликовано`) |

 Фильтр `$types` `findPending` позволяет нескольким потребителям — обычному `Процессору`
 и специализированному экспортеру - совместно использовать один исходящий ящик, не конкурируя за сообщения
 друг друга. @@ЛИНИЯ@@
### Yii3 ДИ
Плагин конфигурации связывает StorageInterface с DbOutboxStorage из
 `config/di.php`. Ядро `yii3-outbox` ничего не привязывает, поэтому этот бэкэнд (или приложение
) является единственным источником `StorageInterface`. Задайте имя таблицы в параметрах
:

```php
// config/params.php
'rasuvaeff/yii3-outbox-db' => ['table' => 'outbox'],
```
## Безопасность
— Все значения записываются с помощью параметризованных команд `yiisoft/db`.
 — `OutboxRowMapper` проверяет каждый столбец и отклоняет поврежденные строки с помощью
 `InvalidOutboxRowException` — без молчаливого приведения.
 — полезные данные могут содержать персональные данные; За сохранение/очистку отвечает приложение
 (помогает `deleteByStatus`). @@ЛИНИЯ@@
## Примеры
Запускаемые сценарии находятся в папке [`examples/`](examples/). @@ЛИНИЯ@@
## Разработка
```bash
make build        # full gate: validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-coverage
make mutation
```
Ядро `yii3-outbox` используется через репозиторий путей, пока оно не опубликовано — см.
 [AGENTS.md](AGENTS.md) для вызова Docker в монорепо-корне. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
