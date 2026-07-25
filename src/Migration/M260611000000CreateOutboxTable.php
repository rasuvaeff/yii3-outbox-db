<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Migration;

use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the outbox table used by {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage}.
 *
 * The table name comes from {@see OutboxTableName}, which `config/di.php`
 * builds from params — one source of truth for the migration and the
 * runtime code alike. Register the migration by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\Yii3OutboxDb\Migration']],
 * ],
 * ```
 *
 * The `idx_<table>_pending` index backs `findPending(array $types, int $limit)`:
 * the pending poll filters on `status` and (optionally) `type`, ordered by
 * `created_at`.
 *
 * @api
 */
final class M260611000000CreateOutboxTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private readonly OutboxTableName $table = new OutboxTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table->value,
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

        // index names follow the table name: in PostgreSQL they are unique per
        // schema, so two installations sharing one schema would collide on a
        // hard-coded name
        $b->createIndex(
            $this->table->value,
            sprintf('idx_%s_pending', $this->table->forIndexName()),
            ['status', 'type', 'created_at'],
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table->value);
    }
}
