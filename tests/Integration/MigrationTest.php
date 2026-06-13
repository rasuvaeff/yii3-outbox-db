<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use M260611000000CreateOutboxTable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversNothing]
final class MigrationTest extends TestCase
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/M260611000000CreateOutboxTable.php';

        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function createsAndDropsOutboxTable(): void
    {
        $migration = new M260611000000CreateOutboxTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('outbox', true);
        $this->assertNotNull($schema);

        foreach (['id', 'type', 'payload', 'status', 'created_at', 'attempts', 'last_attempt_at', 'aggregate_id', 'claimed_by'] as $column) {
            $this->assertNotNull($schema->getColumn($column), "Missing column {$column}");
        }

        $this->assertSame(['id'], $schema->getPrimaryKey());

        $migration->down($this->builder);

        $this->assertNull($this->db->getTableSchema('outbox', true));
    }

    #[Test]
    public function createsTableWithCustomName(): void
    {
        (new M260611000000CreateOutboxTable(table: 'custom_outbox'))->up($this->builder);

        $this->assertNotNull($this->db->getTableSchema('custom_outbox', true));
        $this->assertNull($this->db->getTableSchema('outbox', true));
    }

    #[Test]
    public function migratedTableIsUsableByStorage(): void
    {
        (new M260611000000CreateOutboxTable())->up($this->builder);

        $storage = new DbOutboxStorage(db: $this->db);

        $message = OutboxMessage::create(
            type: 'ab.exposure',
            payload: '{}',
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        );
        $storage->save($message);

        $this->assertCount(1, $storage->findPending());
    }
}
