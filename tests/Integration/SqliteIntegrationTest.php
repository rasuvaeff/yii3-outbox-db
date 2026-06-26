<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbOutboxStorage::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->createTable(name: 'outbox');
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

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

        Assert::notNull($loaded);
        Assert::same($loaded->getId(), $message->getId());
        Assert::same($loaded->getType(), 'ab.exposure');
        Assert::same($loaded->getPayload(), '{"experiment":"checkout"}');
        Assert::same($loaded->getAggregateId(), 'agg-1');
        Assert::same($loaded->getStatus(), OutboxStatus::Pending);
        Assert::same($loaded->getCreatedAt()->format('Y-m-d H:i:s'), '2026-06-11 12:00:00');
    }

    public function getByIdReturnsNullForMissingId(): void
    {
        Assert::null($this->createStorage()->getById('nope'));
    }

    public function findPendingReturnsOnlyPendingOrderedByCreatedAt(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'b', type: 'ab.exposure', createdAt: '2026-06-11 12:02:00'));
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));
        $published = $this->pending(id: 'p', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00')
            ->withStatus(OutboxStatus::Published);
        $storage->save($published);

        $result = $storage->findPending();

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $result), ['a', 'b']);
    }

    public function findPendingFiltersByType(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'exp', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));
        $storage->save($this->pending(id: 'conv', type: 'ab.conversion', createdAt: '2026-06-11 12:01:00'));
        $storage->save($this->pending(id: 'order', type: 'order.created', createdAt: '2026-06-11 12:02:00'));

        $result = $storage->findPending(types: ['ab.exposure', 'ab.conversion']);

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $result), ['exp', 'conv']);
    }

    public function findPendingRespectsLimit(): void
    {
        $storage = $this->createStorage();

        for ($i = 1; $i <= 5; $i++) {
            $storage->save($this->pending(id: 'm' . $i, type: 'ab.exposure', createdAt: '2026-06-11 12:0' . $i . ':00'));
        }

        Assert::count($storage->findPending(limit: 3), 3);
    }

    public function markPublishedMovesMessageOutOfPending(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->markPublished($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));

        Assert::same($storage->findPending(), []);
        $loaded = $storage->getById('m1');
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), OutboxStatus::Published);
        Assert::same($loaded->getAttempts(), 1);
        Assert::notNull($loaded->getLastAttemptAt());
    }

    public function markFailedMovesMessageOutOfPending(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->markFailed($message);

        Assert::same($storage->findPending(), []);
        $loaded = $storage->getById('m1');
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), OutboxStatus::Failed);
    }

    public function saveUpsertsExistingId(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $storage->save($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));

        $loaded = $storage->getById('m1');
        Assert::notNull($loaded);
        Assert::same($loaded->getAttempts(), 1);
        Assert::count(iterator_to_array($this->allRows()), 1);
    }

    public function claimTransitionsPendingToProcessingAndReturnsThem(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'b', type: 'ab.exposure', createdAt: '2026-06-11 12:02:00'));
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));

        $claimed = $storage->claim();

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['a', 'b']);

        foreach ($claimed as $m) {
            Assert::same($m->getStatus(), OutboxStatus::Processing);
        }

        Assert::same($storage->findPending(), []);
    }

    public function claimRespectsLimit(): void
    {
        $storage = $this->createStorage();

        for ($i = 1; $i <= 4; $i++) {
            $storage->save($this->pending(id: 'm' . $i, type: 'ab.exposure', createdAt: '2026-06-11 12:0' . $i . ':00'));
        }

        $claimed = $storage->claim(limit: 2);

        Assert::count($claimed, 2);
        Assert::count($storage->findPending(), 2);
    }

    public function claimFiltersByType(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'exp', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));
        $storage->save($this->pending(id: 'order', type: 'order.created', createdAt: '2026-06-11 12:01:00'));

        $claimed = $storage->claim(types: ['ab.exposure']);

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['exp']);
        Assert::count($storage->findPending(), 1);
    }

    public function claimSecondCallSkipsAlreadyProcessingMessages(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));

        $storage->claim();
        $second = $storage->claim();

        Assert::same($second, []);
    }

    public function saveWithPendingStatusClearsClaimedBy(): void
    {
        $storage = $this->createStorage();
        $message = $this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);

        $claimed = $storage->claim();
        Assert::count($claimed, 1);

        $storage->save($claimed[0]->withStatus(OutboxStatus::Pending));

        $reclaimed = $storage->claim();
        Assert::count($reclaimed, 1);
        Assert::same($reclaimed[0]->getId(), 'a');
    }

    public function deleteByStatusRemovesMatchingRows(): void
    {
        $storage = $this->createStorage();
        $storage->save($this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00')->withStatus(OutboxStatus::Published));
        $storage->save($this->pending(id: 'm2', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));

        $deleted = $storage->deleteByStatus(OutboxStatus::Published);

        Assert::same($deleted, 1);
        Assert::null($storage->getById('m1'));
        Assert::notNull($storage->getById('m2'));
    }

    public function usesCustomTableName(): void
    {
        $this->createTable(name: 'custom_outbox');
        $storage = new DbOutboxStorage(db: $this->db, table: 'custom_outbox');

        $storage->save($this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));

        Assert::count($storage->findPending(), 1);
    }

    public function findPendingThrowsOnCorruptRow(): void
    {
        $this->db->createCommand(sql: "
            INSERT INTO outbox (id, type, payload, status, created_at, attempts, last_attempt_at, aggregate_id)
            VALUES ('bad', 'ab.exposure', '{}', 'pending', 'not-a-date', 0, NULL, NULL)
        ")->execute();

        Expect::exception(InvalidOutboxRowException::class);

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
                aggregate_id    VARCHAR(255),
                claimed_by      VARCHAR(64)
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
