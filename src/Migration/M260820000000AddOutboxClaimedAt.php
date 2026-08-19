<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Migration;

use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds `claimed_at` to the outbox table.
 *
 * `claimed_by` alone cannot tell a fresh claim from an abandoned one: a worker
 * killed between `claim()` and the finalising write leaves its rows in
 * `Processing` forever, and without a timestamp nothing — not even a human with
 * SQL — can decide which ones are safe to take back. The
 * `idx_<table>_processing` index backs
 * {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage::findStaleClaims()}.
 *
 * `down()` works on MySQL and PostgreSQL only: `yiisoft/db-sqlite` cannot drop
 * a column, so rolling back on SQLite throws `NotSupportedException`.
 *
 * @api
 */
final class M260820000000AddOutboxClaimedAt implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private readonly OutboxTableName $table = new OutboxTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn($this->table->value, 'claimed_at', 'string(30)');

        $b->createIndex(
            $this->table->value,
            sprintf('idx_%s_processing', $this->table->forIndexName()),
            ['status', 'claimed_at'],
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex($this->table->value, sprintf('idx_%s_processing', $this->table->forIndexName()));
        $b->dropColumn($this->table->value, 'claimed_at');
    }
}
