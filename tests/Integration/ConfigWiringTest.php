<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. The backend must bind exactly the swappable
 * `StorageInterface` key and nothing the core package already binds —
 * yiisoft/config rejects duplicate keys across vendor packages. The core
 * `yii3-outbox` ships no `config/di.php`, so the application or this backend is
 * the single source of `StorageInterface`.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsOnlyTheStorageKey(): void
    {
        Assert::same(array_keys($this->loadDb([])), [StorageInterface::class]);
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

    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        Assert::same($overlap, [], 'core and -db must not define the same di key (yiisoft/config Duplicate key)');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStorage(array $params): StorageInterface
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[StorageInterface::class];
        Assert::true(is_callable($factory));

        $storage = $factory($this->sqlite());
        Assert::instanceOf($storage, StorageInterface::class);

        return $storage;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCore(): array
    {
        $file = dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-outbox/config/di.php';

        if (!is_file($file)) {
            return [];
        }

        $params = [];

        return require $file;
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');

        return new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
    }
}
