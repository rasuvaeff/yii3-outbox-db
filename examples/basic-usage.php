<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Rasuvaeff\Yii3OutboxDb\Migration\M260820000000AddOutboxClaimedAt;
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
$builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
(new M260611000000CreateOutboxTable())->up($builder);
(new M260820000000AddOutboxClaimedAt())->up($builder);

$storage = new DbOutboxStorage(db: $db, clock: $clock);
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

echo "5. A worker dies right after claiming — recovery puts the row back:\n";
$storage->claim(types: ['order.created']);

// Fifteen minutes later the claim is clearly abandoned: in a real worker the
// threshold is `$clock->now()->modify('-15 minutes')`, computed at recovery
// time. The clock above is frozen, so the example moves the threshold instead.
$threshold = $clock->now()->modify('+15 minutes');

echo '   stuck in Processing: ' . count($storage->findStaleClaims($threshold)) . "\n";
echo '   released: ' . $storage->releaseStaleClaims($threshold) . "\n";
echo '   pending order.created again: ' . count($storage->findPending(types: ['order.created'])) . "\n";

echo "6. A message waiting out its backoff is not claimed at all:\n";
$policy = new RetryPolicy(maxAttempts: 3, delaySeconds: 60);
$storage->save(new OutboxMessage(
    id: 'retrying',
    type: 'order.created',
    payload: '{"orderId":789}',
    status: OutboxStatus::Pending,
    createdAt: $clock->now(),
    attempts: 1,
    lastAttemptAt: $clock->now()->modify('-30 seconds'),
));

$ids = static fn (array $messages): string => implode(
    ', ',
    array_map(static fn (OutboxMessage $m): string => $m->getId(), $messages),
);

echo '   pending: ' . $ids($storage->findPending(types: ['order.created'])) . "\n";
echo '   ready threshold: ' . $policy->readyThreshold($clock->now())->format('Y-m-d H:i:s')
    . " (last attempt was 11:59:30)\n";

$claimed = $storage->claimReady(
    $policy->readyThreshold($clock->now()),
    $policy->getMaxAttempts(),
    ['order.created'],
);

echo '   claimReady took: ' . $ids($claimed) . "\n";
echo '   still pending: ' . $ids($storage->findPending(types: ['order.created'])) . "\n";
echo "   'retrying' was never claimed, so it was never written back\n\n";

$db->close();
