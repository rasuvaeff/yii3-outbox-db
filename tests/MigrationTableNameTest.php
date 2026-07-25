<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests;

use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migration is created by `yiisoft/db-migration` through `Injector::make()`,
 * not by the container, so a test that instantiates it directly proves nothing
 * about whether configuration actually reaches it. These go through the real
 * resolver.
 */
#[Test]
#[Covers(M260611000000CreateOutboxTable::class)]
final class MigrationTableNameTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }

    public function containerBoundTableNameReachesTheMigration(): void
    {
        $migration = $this->make(new SimpleContainer([
            OutboxTableName::class => new OutboxTableName('custom_tbl'),
        ]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('custom_tbl', true));
        Assert::null($this->db->getTableSchema('outbox', true));
    }

    public function withoutABindingTheDefaultNameIsUsed(): void
    {
        // Injector falls back to the parameter default, so the package stays
        // usable with no configuration at all
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('outbox', true));
    }

    public function createsTheDocumentedColumnSet(): void
    {
        // the column list IS the contract with the runtime code: a column
        // silently dropped here surfaces only as a failing query in production
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        $schema = $this->db->getTableSchema('outbox', true);
        Assert::notNull($schema);
        Assert::same(array_keys($schema->getColumns()), [
            'id',
            'type',
            'payload',
            'status',
            'created_at',
            'attempts',
            'last_attempt_at',
            'aggregate_id',
            'claimed_by',
        ]);
    }

    public function indexNamesFollowTheTableName(): void
    {
        // hard-coded index names collide in PostgreSQL, where names are unique
        // per schema rather than per table
        $migration = $this->make(new SimpleContainer([
            OutboxTableName::class => new OutboxTableName('custom_tbl'),
        ]));

        $migration->up($this->builder());

        $indexes = $this->indexNames('custom_tbl');
        Assert::true(in_array('idx_custom_tbl_pending', $indexes, true));
    }

    public function indexesCoverTheDocumentedColumns(): void
    {
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::same($this->indexColumns('idx_outbox_pending'), ['status', 'type', 'created_at']);
    }

    public function downDropsTheConfiguredTable(): void
    {
        $migration = $this->make(new SimpleContainer([
            OutboxTableName::class => new OutboxTableName('custom_tbl'),
        ]));
        $builder = $this->builder();

        $migration->up($builder);
        $migration->down($builder);

        Assert::null($this->db->getTableSchema('custom_tbl', true));
    }

    private function make(SimpleContainer $container): M260611000000CreateOutboxTable
    {
        /** @var M260611000000CreateOutboxTable */
        return (new Injector($container))->make(M260611000000CreateOutboxTable::class);
    }

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $index): array
    {
        $columns = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_info(%s)', $index))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $columns[] = $row['name'];
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $names = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_list(%s)', $table))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }
}
