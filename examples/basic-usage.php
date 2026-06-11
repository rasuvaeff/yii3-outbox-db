<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-11 12:00:00');
    }
};

$driver = new SqliteDriver(dsn: 'sqlite::memory:');
$schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
$db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
$db->open();

$db->createCommand(sql: '
    CREATE TABLE outbox (
        id              VARCHAR(255) PRIMARY KEY,
        type            VARCHAR(255) NOT NULL,
        payload         TEXT         NOT NULL,
        status          VARCHAR(16)  NOT NULL,
        created_at      VARCHAR(30)  NOT NULL,
        attempts        INTEGER      NOT NULL DEFAULT 0,
        last_attempt_at VARCHAR(30),
        aggregate_id    VARCHAR(255)
    )
')->execute();

$storage = new DbOutboxStorage(db: $db);
$outbox = new Outbox(storage: $storage, clock: $clock);

echo "1. Record two events durably:\n";
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout","variant":"green"}');
$outbox->record(type: 'order.created', payload: '{"orderId":456}');
echo "   recorded\n";

echo "2. Worker fetches one consumer's types:\n";
$pending = $storage->findPending(types: ['ab.exposure'], limit: 1000);
foreach ($pending as $message) {
    echo "   {$message->getType()} -> {$message->getPayload()}\n";
}

echo "3. Mark published after a successful batch export:\n";
foreach ($pending as $message) {
    $storage->markPublished($message);
}
echo '   pending ab.exposure now: ' . count($storage->findPending(types: ['ab.exposure'])) . "\n";

echo "4. The unrelated event is untouched:\n";
echo '   pending order.created: ' . count($storage->findPending(types: ['order.created'])) . "\n";

$db->close();
