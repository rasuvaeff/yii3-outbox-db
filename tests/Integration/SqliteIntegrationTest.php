<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;
use Rasuvaeff\Yii3OutboxDb\Tests\Support\CountingProfiler;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbOutboxStorage::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    /**
     * A file-backed database rather than `:memory:` — two connections to
     * `:memory:` are two different databases, and the concurrent-claim test
     * needs both workers looking at the same rows.
     */
    private string $dsn;

    private string $file;

    #[BeforeTest]
    public function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'outbox-db-');

        if ($file === false) {
            throw new \RuntimeException('Cannot create a temporary SQLite file');
        }

        $this->file = $file;
        $this->dsn = 'sqlite:' . $this->file;

        $driver = new SqliteDriver(dsn: $this->dsn);
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->createTable(name: 'outbox');
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();

        if (file_exists($this->file)) {
            unlink($this->file);
        }
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

    public function markPublishedDeletesTheRowWhenConfigured(): void
    {
        $storage = $this->createStorage(deletePublished: true);
        $message = $this->pending(id: 'm1', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00');
        $storage->save($message);
        $storage->save($this->pending(id: 'm2', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));

        $storage->markPublished($message->withAttempt(new \DateTimeImmutable('2026-06-11 12:05:00')));

        Assert::null($storage->getById('m1'));
        Assert::same($storage->getById('m2')?->getStatus(), OutboxStatus::Pending);
    }

    public function markPublishedBatchPublishesEveryRowInOneStatement(): void
    {
        $storage = $this->createStorage(now: '2026-06-11 12:05:00');

        foreach (['a', 'b', 'c'] as $index => $id) {
            $storage->save($this->pending(id: $id, type: 'ab.exposure', createdAt: '2026-06-11 12:0' . $index . ':00'));
        }

        $claimed = $storage->claim();
        $attemptedAt = new \DateTimeImmutable('2026-06-11 12:05:00');
        $batch = array_map(static fn(OutboxMessage $m): OutboxMessage => $m->withAttempt($attemptedAt), \array_slice($claimed, 0, 2));

        $profiler = new CountingProfiler();
        $this->db->setProfiler($profiler);
        $storage->markPublishedBatch($batch);
        $this->db->setProfiler(null);

        Assert::same($profiler->statements, 1);

        foreach (['a', 'b'] as $id) {
            $loaded = $storage->getById($id);
            Assert::notNull($loaded);
            Assert::same($loaded->getStatus(), OutboxStatus::Published);
            Assert::same($loaded->getAttempts(), 1);
            Assert::same($loaded->getLastAttemptAt()?->format('Y-m-d H:i:s'), '2026-06-11 12:05:00');
        }

        Assert::same($storage->getById('c')?->getStatus(), OutboxStatus::Processing);
        $rows = iterator_to_array($this->allRows());
        Assert::null($rows[0]['claimed_by']);
        Assert::null($rows[0]['claimed_at']);
        Assert::notNull($rows[2]['claimed_by']);
    }

    public function markPublishedBatchKeepsEachMessagesOwnAttemptStamp(): void
    {
        $storage = $this->createStorage();
        $storage->save($this->attempted(id: 'once', attempts: 1, lastAttemptAt: '2026-06-11 11:00:00'));
        $storage->save($this->attempted(id: 'once-later', attempts: 1, lastAttemptAt: '2026-06-11 11:20:00'));
        $storage->save($this->attempted(id: 'twice', attempts: 2, lastAttemptAt: '2026-06-11 11:30:00'));
        $storage->save($this->attempted(id: 'twice-same', attempts: 2, lastAttemptAt: '2026-06-11 11:30:00'));
        $storage->save($this->pending(id: 'fresh', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));
        $storage->save($this->pending(id: 'fresh-too', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));
        $claimed = $storage->claim();

        $profiler = new CountingProfiler();
        $this->db->setProfiler($profiler);
        $storage->markPublishedBatch($claimed);
        $this->db->setProfiler(null);

        // (1, 11:00), (1, 11:20), (2, 11:30), (0, none): four distinct stamps
        Assert::same($profiler->statements, 4);

        $expected = [
            'once' => [1, '2026-06-11 11:00:00'],
            'once-later' => [1, '2026-06-11 11:20:00'],
            'twice' => [2, '2026-06-11 11:30:00'],
            'twice-same' => [2, '2026-06-11 11:30:00'],
            'fresh' => [0, null],
            'fresh-too' => [0, null],
        ];

        foreach ($expected as $id => [$attempts, $lastAttemptAt]) {
            $loaded = $storage->getById($id);
            Assert::notNull($loaded);
            Assert::same($loaded->getStatus(), OutboxStatus::Published);
            Assert::same($loaded->getAttempts(), $attempts);
            Assert::same($loaded->getLastAttemptAt()?->format('Y-m-d H:i:s'), $lastAttemptAt);
        }
    }

    public function markPublishedBatchDeletesEveryRowInOneStatementWhenConfigured(): void
    {
        $storage = $this->createStorage(deletePublished: true);

        foreach (['a', 'b', 'c'] as $index => $id) {
            $storage->save($this->pending(id: $id, type: 'ab.exposure', createdAt: '2026-06-11 12:0' . $index . ':00'));
        }

        $claimed = $storage->claim();

        $profiler = new CountingProfiler();
        $this->db->setProfiler($profiler);
        $storage->markPublishedBatch(\array_slice($claimed, 0, 2));
        $this->db->setProfiler(null);

        Assert::same($profiler->statements, 1);
        Assert::null($storage->getById('a'));
        Assert::null($storage->getById('b'));
        Assert::same($storage->getById('c')?->getStatus(), OutboxStatus::Processing);
        Assert::count(iterator_to_array($this->allRows()), 1);
    }

    #[DataProvider('deletePublishedProvider')]
    public function markPublishedBatchWithEmptyListTouchesNothing(bool $deletePublished): void
    {
        $storage = $this->createStorage(deletePublished: $deletePublished);
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));
        $storage->claim();

        $profiler = new CountingProfiler();
        $this->db->setProfiler($profiler);
        $storage->markPublishedBatch([]);
        $this->db->setProfiler(null);

        Assert::same($profiler->statements, 0);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Processing);
    }

    /**
     * The acknowledgement carries no `status = 'processing'` guard: a row the
     * stale-claim cron released and a second worker re-claimed is acknowledged
     * by the first worker's batch all the same. The message was delivered, so
     * removing it is right; the second worker's own acknowledgement then finds
     * nothing to do, and nothing is lost.
     */
    #[DataProvider('deletePublishedProvider')]
    public function markPublishedBatchAcknowledgesARowReleasedAndReclaimedMeanwhile(bool $deletePublished): void
    {
        $first = $this->createStorage(now: '2026-08-20 10:00:00', deletePublished: $deletePublished);
        $second = $this->createStorage(now: '2026-08-20 10:20:00', deletePublished: $deletePublished);
        $first->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));

        $batch = $first->claim();
        Assert::same($first->releaseStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00')), 1);
        $reclaimed = $second->claim();
        Assert::count($reclaimed, 1);

        $first->markPublishedBatch($batch);

        Assert::same($first->findPending(), []);
        Assert::same($first->findStaleClaims(new \DateTimeImmutable('2026-08-20 10:30:00')), []);
        $afterFirst = $first->getById('a');
        Assert::same($afterFirst?->getStatus(), $deletePublished ? null : OutboxStatus::Published);

        $second->markPublishedBatch($reclaimed);

        $afterSecond = $first->getById('a');
        Assert::same($afterSecond?->getStatus(), $deletePublished ? null : OutboxStatus::Published);
        Assert::same($first->findPending(), []);
    }

    public static function deletePublishedProvider(): iterable
    {
        yield 'keep as Published' => [false];
        yield 'delete' => [true];
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

    public function claimStampsClaimedAt(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));

        $storage->claim();

        $rows = iterator_to_array($this->allRows());
        Assert::same($rows[0]['claimed_at'], '2026-08-20 10:00:00');
        Assert::notNull($rows[0]['claimed_by']);
    }

    public function savingAClaimedMessageClearsTheClaim(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));

        $claimed = $storage->claim();
        $storage->save($claimed[0]->withStatus(OutboxStatus::Pending));

        $rows = iterator_to_array($this->allRows());
        Assert::null($rows[0]['claimed_at']);
        Assert::null($rows[0]['claimed_by']);
    }

    public function findStaleClaimsReturnsOnlyClaimsOlderThanTheThreshold(): void
    {
        $old = $this->createStorage(now: '2026-08-20 10:00:00');
        $old->save($this->pending(id: 'old', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));
        $old->claim();

        $fresh = $this->createStorage(now: '2026-08-20 10:30:00');
        $fresh->save($this->pending(id: 'fresh', type: 'ab.exposure', createdAt: '2026-08-20 09:30:00'));
        $fresh->claim();

        $stale = $fresh->findStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00'));

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $stale), ['old']);
    }

    public function findStaleClaimsTreatsAClaimWithoutATimestampAsStale(): void
    {
        // A row claimed by a worker that ran before the claimed_at column
        // existed. Excluding it would strand it forever, which is exactly the
        // state this API exists to end.
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'legacy', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));
        $storage->claim();
        $this->db->createCommand(sql: "UPDATE outbox SET claimed_at = NULL WHERE id = 'legacy'")->execute();

        $stale = $storage->findStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00'));

        Assert::count($stale, 1);
        Assert::same($stale[0]->getId(), 'legacy');
    }

    public function findStaleClaimsReturnsEveryStuckRowOldestFirst(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'second', type: 'ab.exposure', createdAt: '2026-08-20 09:30:00'));
        $storage->save($this->pending(id: 'first', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));
        $storage->claim();

        $stale = $storage->findStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00'));

        // Both rows, and in the order a worker would re-process them.
        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $stale), ['first', 'second']);
    }

    public function releaseStaleClaimsPutsMessagesBackWithoutSpendingAnAttempt(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));
        $storage->claim();

        $released = $storage->releaseStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00'));

        Assert::same($released, 1);
        $message = $storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 0);
        $rows = iterator_to_array($this->allRows());
        Assert::null($rows[0]['claimed_by']);
        Assert::null($rows[0]['claimed_at']);
        Assert::count($storage->claim(), 1);
    }

    public function releaseStaleClaimsLeavesFreshClaimsAlone(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', createdAt: '2026-08-20 09:00:00'));
        $storage->claim();

        Assert::same($storage->releaseStaleClaims(new \DateTimeImmutable('2026-08-20 09:59:00')), 0);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Processing);
    }

    public function releaseStaleClaimsIsBoundedByItsLimit(): void
    {
        $storage = $this->createStorage(now: '2026-08-20 10:00:00');

        foreach (['a', 'b', 'c'] as $index => $id) {
            $storage->save($this->pending(id: $id, type: 'ab.exposure', createdAt: '2026-08-20 09:0' . $index . ':00'));
        }

        $storage->claim();

        Assert::same($storage->releaseStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00'), limit: 2), 2);
        Assert::count($storage->findStaleClaims(new \DateTimeImmutable('2026-08-20 10:15:00')), 1);
    }

    public function twoConnectionsNeverClaimTheSameMessage(): void
    {
        // The conditional UPDATE is what makes claim() safe for concurrent
        // workers; a single-connection test cannot tell it apart from a plain
        // SELECT + UPDATE.
        $second = new SqliteConnection(
            driver: new SqliteDriver(dsn: $this->dsn),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $second->open();

        $storage = $this->createStorage();
        $other = new DbOutboxStorage(db: $second);

        foreach (['a', 'b', 'c', 'd'] as $index => $id) {
            $storage->save($this->pending(id: $id, type: 'ab.exposure', createdAt: '2026-08-20 09:0' . $index . ':00'));
        }

        $first = $storage->claim(limit: 2);
        $rest = $other->claim(limit: 10);

        $firstIds = array_map(static fn(OutboxMessage $m): string => $m->getId(), $first);
        $restIds = array_map(static fn(OutboxMessage $m): string => $m->getId(), $rest);

        Assert::same($firstIds, ['a', 'b']);
        Assert::same($restIds, ['c', 'd']);
        Assert::same(array_intersect($firstIds, $restIds), []);

        $second->close();
    }

    public function claimReadyTakesMessagesThatWereNeverAttempted(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'fresh', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));

        $claimed = $storage->claimReady(new \DateTimeImmutable('2026-06-11 11:59:00'), 3);

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['fresh']);
        Assert::same($claimed[0]->getStatus(), OutboxStatus::Processing);
    }

    public function claimReadyLeavesMessagesStillWaitingOutTheirBackoff(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->attempted(
            id: 'backing-off',
            attempts: 1,
            lastAttemptAt: '2026-06-11 11:59:30',
        ));

        Assert::same($storage->claimReady(new \DateTimeImmutable('2026-06-11 11:59:00'), 3), []);
        Assert::same($storage->getById('backing-off')?->getStatus(), OutboxStatus::Pending);
        Assert::same($storage->getById('backing-off')?->getAttempts(), 1);
    }

    public function claimReadyTakesAMessageAttemptedExactlyAtTheThreshold(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->attempted(
            id: 'boundary',
            attempts: 1,
            lastAttemptAt: '2026-06-11 11:59:00',
        ));

        $claimed = $storage->claimReady(new \DateTimeImmutable('2026-06-11 11:59:00'), 3);

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['boundary']);
    }

    /**
     * Nothing but `markFailed()` terminates an exhausted message, and the
     * caller can only fail what the claim returned. Filtering it out on its
     * backoff would leave it `Pending` forever.
     */
    public function claimReadyTakesExhaustedMessagesInsideTheBackoffWindow(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->attempted(
            id: 'exhausted',
            attempts: 3,
            lastAttemptAt: '2026-06-11 11:59:59',
        ));

        $claimed = $storage->claimReady(new \DateTimeImmutable('2026-06-11 11:59:00'), 3);

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['exhausted']);
    }

    public function claimReadyKeepsTypeFilterLimitAndOrdering(): void
    {
        $storage = $this->createStorage();

        $storage->save($this->pending(id: 'later', type: 'ab.exposure', createdAt: '2026-06-11 12:02:00'));
        $storage->save($this->pending(id: 'earlier', type: 'ab.exposure', createdAt: '2026-06-11 12:01:00'));
        $storage->save($this->pending(id: 'other-type', type: 'order.created', createdAt: '2026-06-11 12:00:00'));
        $storage->save($this->attempted(
            id: 'backing-off',
            attempts: 1,
            lastAttemptAt: '2026-06-11 11:59:30',
            createdAt: '2026-06-11 12:03:00',
        ));

        $claimed = $storage->claimReady(
            new \DateTimeImmutable('2026-06-11 11:59:00'),
            3,
            ['ab.exposure'],
            1,
        );

        Assert::same(array_map(static fn(OutboxMessage $m): string => $m->getId(), $claimed), ['earlier']);
        Assert::same(
            array_map(static fn(OutboxMessage $m): string => $m->getId(), $storage->findPending()),
            ['other-type', 'later', 'backing-off'],
        );
    }

    public function claimReadyStampsClaimedByAndClaimedAt(): void
    {
        $storage = $this->createStorage(now: '2026-06-11 12:05:00');

        $storage->save($this->pending(id: 'fresh', type: 'ab.exposure', createdAt: '2026-06-11 12:00:00'));

        $storage->claimReady(new \DateTimeImmutable('2026-06-11 11:59:00'), 3);

        $row = $this->db
            ->createCommand(sql: 'SELECT claimed_by, claimed_at FROM outbox WHERE id = :id', params: ['id' => 'fresh'])
            ->queryOne();

        Assert::notNull($row);
        Assert::notNull($row['claimed_by']);
        Assert::same($row['claimed_at'], '2026-06-11 12:05:00');
    }

    private function createStorage(?string $now = null, bool $deletePublished = false): DbOutboxStorage
    {
        return new DbOutboxStorage(
            db: $this->db,
            clock: $now === null ? null : new StaticClock(new \DateTimeImmutable($now)),
            deletePublished: $deletePublished,
        );
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

    private function attempted(
        string $id,
        int $attempts,
        string $lastAttemptAt,
        string $createdAt = '2026-06-11 12:00:00',
    ): OutboxMessage {
        return new OutboxMessage(
            id: $id,
            type: 'ab.exposure',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable($createdAt),
            attempts: $attempts,
            lastAttemptAt: new \DateTimeImmutable($lastAttemptAt),
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
                claimed_by      VARCHAR(64),
                claimed_at      VARCHAR(30)
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
