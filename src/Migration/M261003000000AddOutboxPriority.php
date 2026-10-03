<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Migration;

use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds `priority` to the outbox table.
 *
 * {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage} claims by `priority DESC,
 * created_at ASC`. The `idx_<table>_priority` index carries that mixed
 * direction (`priority DESC`), so the claim scans pending rows in order and
 * stops at its limit instead of sorting the whole backlog — measured on
 * MariaDB 12.2 with 100k pending rows: filesort with an ascending index,
 * none with this one. MySQL 8+, MariaDB 10.8+, PostgreSQL and SQLite honour
 * `DESC` in an index; older MySQL/MariaDB parse and ignore it.
 *
 * `down()` works on MySQL and PostgreSQL only: `yiisoft/db-sqlite` cannot drop
 * a column, so rolling back on SQLite throws `NotSupportedException`.
 *
 * @api
 */
final readonly class M261003000000AddOutboxPriority implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private OutboxTableName $table = new OutboxTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn($this->table->value, 'priority', 'smallint NOT NULL DEFAULT 0');
        $b->createIndex(
            $this->table->value,
            sprintf('idx_%s_priority', $this->table->forIndexName()),
            ['status', '[[priority]] DESC', 'created_at'],
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex($this->table->value, sprintf('idx_%s_priority', $this->table->forIndexName()));
        $b->dropColumn($this->table->value, 'priority');
    }
}
