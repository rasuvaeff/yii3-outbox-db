<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\Console\PurgeOutboxCommand;
use Rasuvaeff\Yii3OutboxDb\Console\ReleaseStaleOutboxClaimsCommand;
use Rasuvaeff\Yii3OutboxDb\Console\RequeueFailedOutboxCommand;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Exception\OutboxWriteOutsideTransactionException;
use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. The backend must bind exactly the swappable
 * `StorageInterface` key and nothing the core package already binds —
 * yiisoft/config rejects duplicate keys across vendor packages. The core
 * `yii3-outbox` ships no `config/di.php`, so the application or this backend is
 * the single source of `StorageInterface`. Whether the family merges cleanly is
 * `bin/config-merge-harness @outbox` in the monorepo — no test here can see
 * the sibling packages.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsOnlyItsOwnKeys(): void
    {
        // OutboxTableName is this package's own type; the core binds neither it
        // nor StorageInterface, so there is nothing for yiisoft/config to call
        // a duplicate
        Assert::same(array_keys($this->loadDb([])), [OutboxTableName::class, StorageInterface::class]);
    }

    public function tableNameFactoryAppliesThePrefix(): void
    {
        $definitions = $this->loadDb([
            'rasuvaeff/yii3-outbox-db' => ['table' => 'custom_outbox', 'table_prefix' => 'rsv_'],
        ]);
        $factory = $definitions[OutboxTableName::class];
        Assert::true(is_callable($factory));

        /** @var OutboxTableName $table */
        $table = $factory();
        Assert::same($table->value, 'rsv_custom_outbox');
    }

    public function storageFactoryBuildsDbStorage(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-outbox-db' => ['table' => 'custom_outbox'],
        ]);

        Assert::instanceOf($storage, DbOutboxStorage::class);
    }

    public function storageFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        Assert::instanceOf($this->resolveStorage([]), DbOutboxStorage::class);
    }

    public function storageFactoryKeepsPublishedRowsByDefault(): void
    {
        $storage = $this->resolveStorage([]);
        $message = $this->message();
        $storage->save($message);

        $storage->markPublished($message);

        Assert::same($storage->getById($message->getId())?->getStatus(), OutboxStatus::Published);
    }

    public function storageFactoryHonoursRequireTransaction(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-outbox-db' => ['require_transaction' => true],
        ]);

        Expect::exception(OutboxWriteOutsideTransactionException::class);

        $storage->save($this->message());
    }

    public function storageFactoryHonoursSkipLocked(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-outbox-db' => ['skip_locked' => true],
        ]);
        $storage->save($this->message());

        // the only observable effect on SQLite: the FOR clause is rejected
        Expect::exception(NotSupportedException::class);

        $storage->claim();
    }

    public function paramsRegisterTheThreeConsoleCommands(): void
    {
        /** @var array<string, mixed> $params */
        $params = require dirname(__DIR__, 2) . '/config/params.php';

        Assert::same($params['yiisoft/yii-console'], ['commands' => [
            'outbox:purge' => PurgeOutboxCommand::class,
            'outbox:release-stale' => ReleaseStaleOutboxClaimsCommand::class,
            'outbox:requeue' => RequeueFailedOutboxCommand::class,
        ]]);
        Assert::same($params['rasuvaeff/yii3-outbox-db']['require_transaction'], expected: false);
        Assert::same($params['rasuvaeff/yii3-outbox-db']['skip_locked'], expected: false);
    }

    public function storageFactoryHonoursDeletePublished(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-outbox-db' => ['delete_published' => true],
        ]);
        $message = $this->message();
        $storage->save($message);

        $storage->markPublished($message);

        Assert::null($storage->getById($message->getId()));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStorage(array $params): StorageInterface
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[StorageInterface::class];
        Assert::true(is_callable($factory));

        $tableFactory = $definitions[OutboxTableName::class];
        Assert::true(is_callable($tableFactory));

        $storage = $factory($this->sqlite(), $tableFactory());
        Assert::instanceOf($storage, StorageInterface::class);

        return $storage;
    }

    /**
     * The file reads `$params` from its scope; the closure is what makes that
     * scope explicit enough for rector to see the parameter is used.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        return (static fn(array $params): array => require dirname(__DIR__, 2) . '/config/di.php')($params);
    }

    private function message(): OutboxMessage
    {
        return new OutboxMessage(
            id: 'm1',
            type: 'ab.exposure',
            payload: '{}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        );
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $db = new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
        $db->createCommand(sql: '
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

        return $db;
    }
}
