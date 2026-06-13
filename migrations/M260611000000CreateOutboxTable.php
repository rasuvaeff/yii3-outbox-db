<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the outbox table used by {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage}.
 *
 * The table name defaults to `outbox` and must match the `table` argument of
 * {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage}. To use a custom name, bind the
 * constructor argument in your DI configuration:
 *
 * ```php
 * M260611000000CreateOutboxTable::class => [
 *     '__construct()' => ['table' => 'my_outbox'],
 * ],
 * ```
 *
 * The `idx_outbox_status_type` index backs `findPending(array $types, int $limit)`:
 * the pending poll filters on `status` and (optionally) `type`, ordered by
 * `created_at`.
 */
final class M260611000000CreateOutboxTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private readonly string $table = 'outbox',
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table,
            [
                'id' => 'string(255) NOT NULL PRIMARY KEY',
                'type' => 'string(255) NOT NULL',
                'payload' => 'text NOT NULL',
                'status' => 'string(16) NOT NULL',
                'created_at' => 'string(30) NOT NULL',
                'attempts' => 'integer NOT NULL DEFAULT 0',
                'last_attempt_at' => 'string(30)',
                'aggregate_id' => 'string(255)',
                'claimed_by' => 'string(64)',
            ],
        );

        $b->createIndex($this->table, 'idx_outbox_pending', ['status', 'type', 'created_at']);
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table);
    }
}
