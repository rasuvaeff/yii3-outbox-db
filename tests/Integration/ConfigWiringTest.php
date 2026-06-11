<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
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
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsOnlyTheStorageKey(): void
    {
        $this->assertSame([StorageInterface::class], array_keys($this->loadDb([])));
    }

    #[Test]
    public function storageFactoryBuildsDbStorage(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-outbox-db' => ['table' => 'custom_outbox'],
        ]);

        $this->assertInstanceOf(DbOutboxStorage::class, $storage);
    }

    #[Test]
    public function storageFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        $this->assertInstanceOf(DbOutboxStorage::class, $this->resolveStorage([]));
    }

    #[Test]
    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        $this->assertSame(
            [],
            $overlap,
            'core and -db must not define the same di key (yiisoft/config Duplicate key)',
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStorage(array $params): StorageInterface
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[StorageInterface::class];
        $this->assertIsCallable($factory);

        $storage = $factory($this->sqlite());
        $this->assertInstanceOf(StorageInterface::class, $storage);

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
