<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
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

// the bundled migration is the schema's single source of truth — a hand-written
// CREATE TABLE here silently drifts (this example used to miss `claimed_by`,
// which claim() needs)
(new M260611000000CreateOutboxTable())->up(
    new MigrationBuilder(db: $db, informer: new NullMigrationInformer()),
);

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
