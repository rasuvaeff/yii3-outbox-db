<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Integration;

use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Rasuvaeff\Yii3OutboxDb\Migration\M260820000000AddOutboxClaimedAt;
use Rasuvaeff\Yii3OutboxDb\Migration\M261003000000AddOutboxPriority;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(M260820000000AddOutboxClaimedAt::class)]
#[Covers(M261003000000AddOutboxPriority::class)]
final class MigrationStepsTest
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
        (new M260611000000CreateOutboxTable())->up($this->builder);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function claimedAtMigrationAddsTheColumnAndTheProcessingIndex(): void
    {
        (new M260820000000AddOutboxClaimedAt())->up($this->builder);

        Assert::notNull($this->db->getTableSchema('outbox', true)?->getColumn('claimed_at'));
        Assert::same($this->indexColumns('idx_outbox_processing'), ['status', 'claimed_at']);
    }

    public function claimedAtMigrationDownDropsTheIndexBeforeTheColumn(): void
    {
        $migration = new M260820000000AddOutboxClaimedAt();
        $migration->up($this->builder);

        // yiisoft/db-sqlite cannot drop a column, so down() fails on the
        // second step; what it must have done by then is drop the index.
        $this->runDown($migration);

        Assert::same($this->indexColumns('idx_outbox_processing'), []);
    }

    public function priorityMigrationAddsTheColumnAndTheClaimOrderIndex(): void
    {
        (new M261003000000AddOutboxPriority())->up($this->builder);

        Assert::notNull($this->db->getTableSchema('outbox', true)?->getColumn('priority'));
        Assert::same($this->indexColumns('idx_outbox_priority'), ['status', 'priority', 'created_at']);
    }

    public function priorityMigrationDownDropsTheIndexBeforeTheColumn(): void
    {
        $migration = new M261003000000AddOutboxPriority();
        $migration->up($this->builder);

        $this->runDown($migration);

        Assert::same($this->indexColumns('idx_outbox_priority'), []);
    }

    private function runDown(M260820000000AddOutboxClaimedAt|M261003000000AddOutboxPriority $migration): void
    {
        $failed = false;

        try {
            $migration->down($this->builder);
        } catch (\Throwable) {
            $failed = true;
        }

        Assert::true($failed, 'down() should reach the unsupported column drop');
    }

    /**
     * @return list<string|null> column names in index order
     */
    private function indexColumns(string $index): array
    {
        /** @var list<array{name: string|null}> $rows */
        $rows = $this->db->createCommand(sql: "PRAGMA index_info('{$index}')")->queryAll();

        return array_map(static fn(array $row): ?string => $row['name'], $rows);
    }
}
