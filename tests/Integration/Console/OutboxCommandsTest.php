<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration\Console;

use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RequeueableStorageInterface;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\Console\PurgeOutboxCommand;
use Rasuvaeff\Yii3OutboxDb\Console\ReleaseStaleOutboxClaimsCommand;
use Rasuvaeff\Yii3OutboxDb\Console\RequeueFailedOutboxCommand;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
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
#[Covers(PurgeOutboxCommand::class)]
#[Covers(ReleaseStaleOutboxClaimsCommand::class)]
#[Covers(RequeueFailedOutboxCommand::class)]
final class OutboxCommandsTest
{
    private const string NOW = '2026-06-11 12:00:00';

    private ConnectionInterface $db;

    private DbOutboxStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->db->createCommand(sql: '
            CREATE TABLE outbox (
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
        ')->execute();
        $this->storage = new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable(self::NOW)));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    // --- outbox:purge -------------------------------------------------------

    public function purgeDeletesPublishedRowsByDefault(): void
    {
        $this->save('pub', OutboxStatus::Published, '2026-06-01 12:00:00');
        $this->save('failed', OutboxStatus::Failed, '2026-06-01 12:00:00');
        $this->save('pending', OutboxStatus::Pending, '2026-06-01 12:00:00');
        $tester = $this->purge();

        Assert::same($tester->execute([]), Command::SUCCESS);

        Assert::true(str_contains($tester->getDisplay(), 'Deleted 1 published outbox row(s)'));
        Assert::null($this->storage->getById('pub'));
        Assert::notNull($this->storage->getById('failed'));
        Assert::notNull($this->storage->getById('pending'));
    }

    public function purgeHonoursStatusAndAge(): void
    {
        $this->save('old', OutboxStatus::Failed, '2026-06-01 12:00:00');
        $this->save('recent', OutboxStatus::Failed, '2026-06-10 12:00:00');
        $this->save('pub', OutboxStatus::Published, '2026-06-01 12:00:00');
        $tester = $this->purge();

        Assert::same($tester->execute(['--status' => 'failed', '--older-than' => '7d']), Command::SUCCESS);

        Assert::true(str_contains($tester->getDisplay(), 'Deleted 1 failed outbox row(s)'));
        Assert::null($this->storage->getById('old'));
        Assert::notNull($this->storage->getById('recent'));
        Assert::notNull($this->storage->getById('pub'));
    }

    public function purgeAgeIsMeasuredFromTheInjectedClock(): void
    {
        // 2026-06-04 12:00:00 is exactly 7d before NOW: not older than
        $this->save('boundary', OutboxStatus::Published, '2026-06-04 12:00:00');
        $this->save('older', OutboxStatus::Published, '2026-06-04 11:59:59');
        $tester = $this->purge();

        $tester->execute(['--older-than' => '7d']);

        Assert::notNull($this->storage->getById('boundary'));
        Assert::null($this->storage->getById('older'));
    }

    public function purgeWithoutAClockUsesTheSystemTime(): void
    {
        $this->save('ancient', OutboxStatus::Published, '2000-01-01 00:00:00');
        $tester = new CommandTester(new PurgeOutboxCommand($this->storage));

        Assert::same($tester->execute(['--older-than' => '1d']), Command::SUCCESS);
        Assert::null($this->storage->getById('ancient'));
    }

    public function purgeRejectsPendingProcessingAndUnknownStatuses(): void
    {
        $this->save('pending', OutboxStatus::Pending, '2026-06-01 12:00:00');
        $tester = $this->purge();

        foreach (['pending', 'processing', 'gone', ''] as $status) {
            Assert::same($tester->execute(['--status' => $status]), Command::INVALID);
            Assert::true(str_contains($tester->getDisplay(), '--status must be "published" or "failed"'));
        }

        Assert::notNull($this->storage->getById('pending'));
    }

    public function purgeRejectsAMalformedAge(): void
    {
        $this->save('pub', OutboxStatus::Published, '2026-06-01 12:00:00');
        $tester = $this->purge();

        Assert::same($tester->execute(['--older-than' => 'yesterday']), Command::INVALID);

        Assert::true(str_contains($tester->getDisplay(), '--older-than: Invalid age "yesterday"'));
        Assert::notNull($this->storage->getById('pub'));
    }

    public function purgeRefusesAStorageThatIsNotTheDbOne(): void
    {
        $tester = new CommandTester(new PurgeOutboxCommand(new InMemoryStorage()));

        Assert::same($tester->execute([]), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), 'outbox:purge needs Rasuvaeff\Yii3OutboxDb\DbOutboxStorage, got Rasuvaeff\Yii3Outbox\InMemoryStorage'));
    }

    // --- outbox:release-stale -----------------------------------------------

    public function releaseStaleReleasesClaimsOlderThanTheAge(): void
    {
        $this->save('stale', OutboxStatus::Pending, '2026-06-11 10:00:00');
        $this->save('fresh', OutboxStatus::Pending, '2026-06-11 10:00:00');
        // claimed at 11:00 and 11:55 respectively
        (new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable('2026-06-11 11:00:00'))))->claim(limit: 1);
        (new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable('2026-06-11 11:55:00'))))->claim(limit: 1);
        $tester = $this->releaseStale();

        Assert::same($tester->execute(['--claimed-before' => '30m']), Command::SUCCESS);

        Assert::true(str_contains($tester->getDisplay(), 'Released 1 stale outbox claim(s)'));
        Assert::same($this->storage->getById('stale')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('fresh')?->getStatus(), OutboxStatus::Processing);
    }

    public function releaseStaleDefaultsToFifteenMinutes(): void
    {
        $this->save('a', OutboxStatus::Pending, '2026-06-11 10:00:00');
        $this->save('b', OutboxStatus::Pending, '2026-06-11 10:00:00');
        (new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable('2026-06-11 11:44:00'))))->claim(limit: 1);
        (new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable('2026-06-11 11:46:00'))))->claim(limit: 1);
        $tester = $this->releaseStale();

        $tester->execute([]);

        Assert::true(str_contains($tester->getDisplay(), 'Released 1 stale outbox claim(s)'));
        Assert::same($this->storage->getById('a')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('b')?->getStatus(), OutboxStatus::Processing);
    }

    public function releaseStaleHonoursTheLimit(): void
    {
        $this->save('a', OutboxStatus::Pending, '2026-06-11 10:00:00');
        $this->save('b', OutboxStatus::Pending, '2026-06-11 10:01:00');
        (new DbOutboxStorage(db: $this->db, clock: new StaticClock(new \DateTimeImmutable('2026-06-11 11:00:00'))))->claim();
        $tester = $this->releaseStale();

        $tester->execute(['--limit' => '1']);

        Assert::true(str_contains($tester->getDisplay(), 'Released 1 stale outbox claim(s)'));
        Assert::same($this->storage->getById('a')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('b')?->getStatus(), OutboxStatus::Processing);
    }

    public function releaseStaleRejectsAMalformedAgeOrLimit(): void
    {
        $tester = $this->releaseStale();

        Assert::same($tester->execute(['--claimed-before' => '15']), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), '--claimed-before: Invalid age "15"'));

        Assert::same($tester->execute(['--limit' => '0']), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), '--limit must be a positive integer'));
    }

    public function releaseStaleWithoutAClockUsesTheSystemTime(): void
    {
        $this->save('a', OutboxStatus::Pending, '2026-06-11 10:00:00');
        // claimed "now" by the storage's own frozen clock, which is years ago
        $this->storage->claim();
        $tester = new CommandTester(new ReleaseStaleOutboxClaimsCommand($this->storage));

        Assert::same($tester->execute(['--claimed-before' => '1m']), Command::SUCCESS);
        Assert::true(str_contains($tester->getDisplay(), 'Released 1 stale outbox claim(s)'));
    }

    public function releaseStaleRefusesAStorageThatIsNotTheDbOne(): void
    {
        $tester = new CommandTester(new ReleaseStaleOutboxClaimsCommand(new InMemoryStorage()));

        Assert::same($tester->execute([]), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), 'outbox:release-stale needs'));
    }

    // --- outbox:requeue -----------------------------------------------------

    public function requeueMovesEveryFailedMessageBackToPending(): void
    {
        $this->save('f1', OutboxStatus::Failed, '2026-06-11 10:00:00', attempts: 3);
        $this->save('f2', OutboxStatus::Failed, '2026-06-11 10:01:00', attempts: 3);
        $this->save('pub', OutboxStatus::Published, '2026-06-11 10:00:00');
        $tester = $this->requeue();

        Assert::same($tester->execute([]), Command::SUCCESS);

        Assert::true(str_contains($tester->getDisplay(), 'Requeued 2 failed outbox message(s)'));
        Assert::same($this->storage->getById('f1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('f1')?->getAttempts(), 0);
        Assert::same($this->storage->getById('f2')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('pub')?->getStatus(), OutboxStatus::Published);
    }

    public function requeueFiltersByTypeAndHonoursTheLimit(): void
    {
        $this->save('a1', OutboxStatus::Failed, '2026-06-11 10:00:00', type: 'a');
        $this->save('a2', OutboxStatus::Failed, '2026-06-11 10:01:00', type: 'a');
        $this->save('b1', OutboxStatus::Failed, '2026-06-11 10:00:00', type: 'b');
        $this->save('c1', OutboxStatus::Failed, '2026-06-11 10:00:00', type: 'c');
        $tester = $this->requeue();

        $tester->execute(['--type' => ['a', 'b'], '--limit' => '2']);

        Assert::true(str_contains($tester->getDisplay(), 'Requeued 2 failed outbox message(s)'));
        Assert::same($this->storage->getById('a1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('b1')?->getStatus(), OutboxStatus::Pending);
        Assert::same($this->storage->getById('a2')?->getStatus(), OutboxStatus::Failed);
        Assert::same($this->storage->getById('c1')?->getStatus(), OutboxStatus::Failed);
    }

    public function requeuePassesACleanTypeListAndTheLimitToTheStorage(): void
    {
        $recording = new class implements RequeueableStorageInterface {
            /** @var list<array{list<string>, int}> */
            public array $calls = [];

            public function findFailed(array $types = [], int $limit = 1000): array
            {
                $this->calls[] = [$types, $limit];

                return [];
            }

            public function requeue(OutboxMessage $message): bool
            {
                return true;
            }

            public function save(OutboxMessage $message): void {}

            public function findPending(array $types = [], int $limit = 1000): array
            {
                return [];
            }

            public function claim(array $types = [], int $limit = 1000): array
            {
                return [];
            }

            public function markPublished(OutboxMessage $message): void {}

            public function markFailed(OutboxMessage $message): void {}

            public function getById(string $id): ?OutboxMessage
            {
                return null;
            }
        };
        $tester = new CommandTester(new RequeueFailedOutboxCommand($recording));

        $tester->execute(['--type' => ['', 'a', '', 'b'], '--limit' => '7']);

        // empty values dropped, keys renumbered: a list, exactly as the
        // interface documents the parameter
        Assert::same($recording->calls, [[['a', 'b'], 7]]);
    }

    public function requeueIgnoresEmptyTypeValues(): void
    {
        $this->save('a1', OutboxStatus::Failed, '2026-06-11 10:00:00', type: 'a');
        $tester = $this->requeue();

        $tester->execute(['--type' => ['']]);

        // an empty --type is "no filter", not "a type named nothing"
        Assert::true(str_contains($tester->getDisplay(), 'Requeued 1 failed outbox message(s)'));
    }

    public function requeueRejectsAMalformedLimit(): void
    {
        $tester = $this->requeue();

        Assert::same($tester->execute(['--limit' => 'all']), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), '--limit must be a positive integer'));
    }

    public function requeueWorksWithAnyRequeueableStorage(): void
    {
        $memory = new InMemoryStorage();
        $memory->save($this->message('f', OutboxStatus::Failed, '2026-06-11 10:00:00'));
        $tester = new CommandTester(new RequeueFailedOutboxCommand($memory));

        Assert::same($tester->execute([]), Command::SUCCESS);
        Assert::same($memory->getById('f')?->getStatus(), OutboxStatus::Pending);
    }

    public function requeueRefusesAStorageThatCannotRequeue(): void
    {
        $plain = new class implements StorageInterface {
            public function save(OutboxMessage $message): void {}

            public function findPending(array $types = [], int $limit = 1000): array
            {
                return [];
            }

            public function claim(array $types = [], int $limit = 1000): array
            {
                return [];
            }

            public function markPublished(OutboxMessage $message): void {}

            public function markFailed(OutboxMessage $message): void {}

            public function getById(string $id): ?OutboxMessage
            {
                return null;
            }
        };
        $tester = new CommandTester(new RequeueFailedOutboxCommand($plain));

        Assert::same($tester->execute([]), Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), 'outbox:requeue needs a Rasuvaeff\Yii3Outbox\RequeueableStorageInterface'));
    }

    // --- helpers ------------------------------------------------------------

    private function purge(): CommandTester
    {
        return new CommandTester(new PurgeOutboxCommand($this->storage, new StaticClock(new \DateTimeImmutable(self::NOW))));
    }

    private function releaseStale(): CommandTester
    {
        return new CommandTester(new ReleaseStaleOutboxClaimsCommand($this->storage, new StaticClock(new \DateTimeImmutable(self::NOW))));
    }

    private function requeue(): CommandTester
    {
        return new CommandTester(new RequeueFailedOutboxCommand($this->storage));
    }

    private function save(string $id, OutboxStatus $status, string $createdAt, int $attempts = 0, string $type = 'ab.exposure'): void
    {
        $this->storage->save($this->message($id, $status, $createdAt, $attempts, $type));
    }

    private function message(string $id, OutboxStatus $status, string $createdAt, int $attempts = 0, string $type = 'ab.exposure'): OutboxMessage
    {
        return new OutboxMessage(
            id: $id,
            type: $type,
            payload: '{}',
            status: $status,
            createdAt: new \DateTimeImmutable($createdAt),
            attempts: $attempts,
            lastAttemptAt: $attempts === 0 ? null : new \DateTimeImmutable($createdAt),
        );
    }
}
