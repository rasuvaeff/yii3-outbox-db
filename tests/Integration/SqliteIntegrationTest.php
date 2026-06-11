<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversClass(DbOutboxStorage::class)]
final class SqliteIntegrationTest extends TestCase
{
    private ConnectionInterface $db;

    #[\Override]
    protected function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->createTable(name: 'outbox');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function savesAndReadsBackMessage(): void
    {
        $storage = $this->createStorage();

        $message = OutboxMessage::create(
            type: 'ab.exposure',
            payload: '{"experiment":"checkout"}',
            aggregateId: 'agg-1',
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        );

        $storage->save($message);

        $loaded = $storage->getById($message->getId());

        $this->assertNotNull($loaded);
        $this->assertSame($message->getId(), $loaded->getId());
        $this->assertSame('ab.exposure', $loaded->getType());
        $this->assertSame('{"experiment":"checkout"}', $loaded->getPayload());
        $this->assertSame('agg-1', $loaded->getAggregateId());
        $this->assertSame(OutboxStatus::Pending, $loaded->getStatus());
        $this->assertSame('2026-06-11 12:00:00', $loaded->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function getByIdReturnsNullForMissingId(): void
    {
        $this->assertNull($this->createStorage()->getById('nope'));
    }

    #[Test]
    public function findPendingReturnsOnlyPendingOrderedByCreatedAt(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'b', type: 'ab.exposure', createdAt: '2026-06-11 12:02:00'));
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));
        $published = $this->pending(id: 'p', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00')
            ->withStatus(OutboxStatus::Published);
        $storage->save($published);

        $result = $storage->findPending();

        $this->assertSame(['a', 'b'], array_map(static fn(OutboxMessage $m): string => $m->getId(), $result));
    }

    #[Test]
    public function findPendingFiltersByType(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'exp', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));
        $storage->save($this->pending(id: 'conv', type: 'ab.conversion', createdAt: '2026-06-11 12:01:00'));
        $storage->save($this->pending(id: 'order', type: 'order.created', createdAt: '2026-06-11 12:02:00'));

        $result = $storage->findPending(types: ['ab.exposure', 'ab.conversion']);

        $this->assertSame(['exp', 'conv'], array_map(static fn(OutboxMessage $m): string => $m->getId(), $result));
    }

    #[Test]
    public function findPendingRespectsLimit(): void
    {
        $storage = $this->createStorage();

        for ($i = 1; $i <= 5; $i++) {
            $storage->save($this->pending(id: 'm' . $i, type: 'ab.exposure', createdAt: '2026-06-11 12:0' . $i . ':00'));
        }

        $this->assertCount(3, $storage->findPending(limit: 3));
    }

    #[Test]
    public function markPublishedMovesMessageOutOfPending(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->markPublished($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));

        $this->assertSame([], $storage->findPending());
        $loaded = $storage->getById('m1');
        $this->assertNotNull($loaded);
        $this->assertSame(OutboxStatus::Published, $loaded->getStatus());
        $this->assertSame(1, $loaded->getAttempts());
        $this->assertNotNull($loaded->getLastAttemptAt());
    }

    #[Test]
    public function markFailedMovesMessageOutOfPending(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->markFailed($message);

        $this->assertSame([], $storage->findPending());
        $loaded = $storage->getById('m1');
        $this->assertNotNull($loaded);
        $this->assertSame(OutboxStatus::Failed, $loaded->getStatus());
    }

    #[Test]
    public function saveUpsertsExistingId(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->save($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));

        $loaded = $storage->getById('m1');
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->getAttempts());
        $this->assertCount(1, iterator_to_array($this->allRows()));
    }

    #[Test]
    public function deleteByStatusRemovesMatchingRows(): void
    {
        $storage = $this->createStorage();
        $storage->save($this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00')->withStatus(OutboxStatus::Published));
        $storage->save($this->pending(id: 'm2', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));

        $deleted = $storage->deleteByStatus(OutboxStatus::Published);

        $this->assertSame(1, $deleted);
        $this->assertNull($storage->getById('m1'));
        $this->assertNotNull($storage->getById('m2'));
    }

    #[Test]
    public function usesCustomTableName(): void
    {
        $this->createTable(name: 'custom_outbox');
        $storage = new DbOutboxStorage(db: $this->db, table: 'custom_outbox');

        $storage->save($this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));

        $this->assertCount(1, $storage->findPending());
    }

    #[Test]
    public function findPendingThrowsOnCorruptRow(): void
    {
        $this->db->createCommand(sql: "
            INSERT INTO outbox (id, type, payload, status, created_at, attempts, last_attempt_at, aggregate_id)
            VALUES ('bad', 'ab.exposure', '{}', 'pending', 'not-a-date', 0, NULL, NULL)
        ")->execute();

        $this->expectException(InvalidOutboxRowException::class);

        $this->createStorage()->findPending();
    }

    private function createStorage(): DbOutboxStorage
    {
        return new DbOutboxStorage(db: $this->db);
    }

    private function pending(string $id, string $type, string $createdAt): OutboxMessage
    {
        return new OutboxMessage(
            id: $id,
            type: $type,
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable($createdAt),
        );
    }

    private function createTable(string $name): void
    {
        $this->db->createCommand(sql: "
            CREATE TABLE {$name} (
                id              VARCHAR(255) PRIMARY KEY,
                type            VARCHAR(255) NOT NULL,
                payload         TEXT         NOT NULL,
                status          VARCHAR(16)  NOT NULL,
                created_at      VARCHAR(30)  NOT NULL,
                attempts        INTEGER      NOT NULL DEFAULT 0,
                last_attempt_at VARCHAR(30),
                aggregate_id    VARCHAR(255)
            )
        ")->execute();
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function allRows(): iterable
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->createCommand(sql: 'SELECT * FROM outbox')->queryAll();

        return $rows;
    }
}
