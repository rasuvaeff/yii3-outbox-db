<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Rasuvaeff\Yii3OutboxDb\Migration\M260820000000AddOutboxClaimedAt;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The bundled migrations are otherwise only ever applied to SQLite, which
 * accepts DDL the other engines reject and cannot run `down()` of the second
 * migration at all (`dropColumn`). This applies both, up and down, and drives
 * the storage through the writes that differ most between engines — the
 * `upsert()` behind `save()`, the multi-row `insertBatch()`, the token claim,
 * the aggregate `stats()` — on MySQL and PostgreSQL. Runs only when
 * `OUTBOX_TEST_DB` names a live server; CI supplies both.
 */
#[Test]
#[CoversNothing]
final class CrossDatabaseMigrationTest
{
    public function migrationsAndStorageWorkOnTheConfiguredDatabase(): void
    {
        $database = getenv('OUTBOX_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $db->createCommand('DROP TABLE IF EXISTS outbox')->execute();

            $builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());
            $create = new M260611000000CreateOutboxTable();
            $addClaimedAt = new M260820000000AddOutboxClaimedAt();

            $create->up($builder);
            $addClaimedAt->up($builder);

            $schema = $db->getTableSchema('outbox', true);
            Assert::notNull($schema);
            Assert::notNull($schema->getColumn('claimed_at'));

            $storage = new DbOutboxStorage(db: $db);

            // upsert: insert, then the same id again with new state
            $message = $this->message('m1', '2026-06-11 12:00:00');
            $storage->save($message);
            $storage->save($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));
            Assert::same($storage->getById('m1')?->getAttempts(), 1);

            // multi-row insert
            $storage->saveBatch([
                $this->message('m2', '2026-06-11 12:01:00'),
                $this->message('m3', '2026-06-11 12:02:00'),
            ]);
            Assert::count($storage->findPending(), 3);

            // token claim, then a batch acknowledgement
            $claimed = $storage->claimReady(new \DateTimeImmutable('2026-06-11 12:10:00'), 3, limit: 2);
            Assert::count($claimed, 2);
            Assert::same($claimed[0]->getStatus(), OutboxStatus::Processing);
            $storage->markPublishedBatch($claimed);
            Assert::count($storage->findPending(), 1);

            // Failed -> requeue, decided by the row's status in one UPDATE
            $storage->markFailed($storage->getById('m3') ?? throw new \RuntimeException('m3 missing'));
            Assert::count($storage->findFailed(), 1);
            Assert::true($storage->requeue($storage->getById('m3') ?? throw new \RuntimeException('m3 missing')));
            Assert::false($storage->requeue($storage->getById('m3') ?? throw new \RuntimeException('m3 missing')));

            // aggregate
            $stats = $storage->stats();
            // m1 and m2 published, m3 requeued
            Assert::same([$stats->pending, $stats->processing, $stats->published, $stats->failed], [1, 0, 2, 0]);
            Assert::same($stats->oldestPendingCreatedAt?->format('Y-m-d H:i:s'), '2026-06-11 12:02:00');

            // retention with a threshold
            Assert::same($storage->deleteByStatus(OutboxStatus::Published, new \DateTimeImmutable('2026-06-11 12:00:30')), 1);

            $addClaimedAt->down($builder);
            Assert::null($db->getTableSchema('outbox', true)?->getColumn('claimed_at'));

            $create->down($builder);
            Assert::null($db->getTableSchema('outbox', true));
        } finally {
            $db->createCommand('DROP TABLE IF EXISTS outbox')->execute();
            $db->close();
        }
    }

    private function message(string $id, string $createdAt): OutboxMessage
    {
        return new OutboxMessage(
            id: $id,
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable($createdAt),
        );
    }

    private function connection(string $database): ConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $mysqlPort = getenv('OUTBOX_TEST_MYSQL_PORT') ?: '3306';
        $pgsqlPort = getenv('OUTBOX_TEST_PGSQL_PORT') ?: '5432';

        return $database === 'mysql'
            ? new MysqlConnection(
                driver: new MysqlDriver(
                    dsn: sprintf('mysql:host=127.0.0.1;port=%s;dbname=outbox;charset=utf8mb4', $mysqlPort),
                    username: 'root',
                    password: 'outbox',
                ),
                schemaCache: $cache,
            )
            : new PgsqlConnection(
                driver: new PgsqlDriver(
                    dsn: sprintf('pgsql:host=127.0.0.1;port=%s;dbname=outbox', $pgsqlPort),
                    username: 'postgres',
                    password: 'outbox',
                ),
                schemaCache: $cache,
            );
    }
}
