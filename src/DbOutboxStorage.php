<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;
use Rasuvaeff\Yii3Outbox\BatchSavingStorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStats;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RequeueableStorageInterface;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;
use Rasuvaeff\Yii3Outbox\StatsAwareStorageInterface;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\Exception\OutboxWriteOutsideTransactionException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * SQL-backed {@see StorageInterface}.
 *
 * It implements {@see RetryAwareStorageInterface}, so `Processor` claims
 * through {@see self::claimReady()} and a message still waiting out its backoff
 * is never taken from the table — rather than claimed, discarded in PHP and
 * written straight back.
 *
 * It also implements {@see BatchAcknowledgingStorageInterface}: a consumer that
 * delivers a batch as a unit acknowledges it through
 * {@see self::markPublishedBatch()} with one statement instead of one upsert
 * per message. Whether an acknowledged row stays as `Published` or is deleted
 * is `$deletePublished`, and it governs {@see self::markPublished()} too — every
 * producer of acknowledgements then agrees on what the table holds.
 *
 * The other optional capabilities of the core are here too:
 * {@see BatchSavingStorageInterface} (one multi-row insert for
 * `Outbox::recordMany()`), {@see RequeueableStorageInterface} (`Failed` back
 * to `Pending`, decided by the row's current status in one `UPDATE`) and
 * {@see StatsAwareStorageInterface} (one `GROUP BY status` query).
 *
 * Beyond the interfaces it exposes two operational helpers that no storage
 * contract can express: {@see self::deleteByStatus()} for retention, and
 * {@see self::findStaleClaims()} / {@see self::releaseStaleClaims()} for the
 * rows a killed worker leaves behind in `Processing`.
 *
 * With `requireTransaction: true` a write that creates a new row — the one
 * `Outbox::record()` makes — throws {@see OutboxWriteOutsideTransactionException}
 * unless a transaction is open on the connection. The outbox pattern only holds
 * when that write commits with the business row, and this is the cheap way to
 * catch a `record()` that was placed outside the transaction, in the
 * environment where it is cheap to catch: development and CI. A state change
 * on an existing row — a worker saving a message back as `Pending`, a release —
 * is not subject to it, which costs one existence check per non-transactional
 * `save()` in that mode.
 *
 * @api
 */
final readonly class DbOutboxStorage implements
    RetryAwareStorageInterface,
    BatchAcknowledgingStorageInterface,
    BatchSavingStorageInterface,
    RequeueableStorageInterface,
    StatsAwareStorageInterface
{
    private OutboxRowMapper $mapper;

    private string $table;

    /**
     * @param non-empty-string $table
     * @param bool $deletePublished delete an acknowledged row instead of keeping
     *                              it as `Published`: the table then holds only
     *                              `Pending`/`Processing`/`Failed` rows and needs
     *                              no `deleteByStatus(Published)` purge, at the
     *                              price of the audit trail of what was sent
     * @param bool $requireTransaction throw {@see OutboxWriteOutsideTransactionException}
     *                                 when a new row is written with no
     *                                 transaction open — see the class description
     *
     * @throws \InvalidArgumentException when the name is not a valid identifier
     */
    public function __construct(
        private ConnectionInterface $db,
        string $table = 'outbox',
        private ?ClockInterface $clock = null,
        private bool $deletePublished = false,
        private bool $requireTransaction = false,
    ) {
        // validation lives in the value object, so the storage and the bundled
        // migration cannot disagree about what a valid table name is
        $this->table = (new OutboxTableName($table))->value;
        $this->mapper = new OutboxRowMapper();
    }

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        if ($this->requireTransaction && !$this->inTransaction() && !$this->exists($message->getId())) {
            throw new OutboxWriteOutsideTransactionException(sprintf(
                'Outbox message "%s" is being recorded outside a transaction; Outbox::record() must run inside the business transaction',
                $message->getId(),
            ));
        }

        $this->db->createCommand()->upsert(
            table: $this->table,
            insertColumns: $this->toColumns(message: $message),
        )->execute();
    }

    /**
     * One multi-row `INSERT`. The rows are new by contract — this is what
     * `Outbox::recordMany()` writes — so unlike {@see self::save()} it does not
     * upsert, and a duplicate id is the database's error to raise.
     */
    #[\Override]
    public function saveBatch(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        if ($this->requireTransaction && !$this->inTransaction()) {
            throw new OutboxWriteOutsideTransactionException(sprintf(
                '%d outbox messages are being recorded outside a transaction; Outbox::recordMany() must run inside the business transaction',
                \count($messages),
            ));
        }

        $rows = [];

        foreach ($messages as $message) {
            $rows[] = $this->toColumns(message: $message);
        }

        $this->db->createCommand()->insertBatch(table: $this->table, rows: $rows)->execute();
    }

    private function inTransaction(): bool
    {
        return $this->db->getTransaction()?->isActive() === true;
    }

    private function exists(string $id): bool
    {
        return (new Query($this->db))->from($this->table)->where(condition: ['id' => $id])->exists();
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->findByStatus(OutboxStatus::Pending, $types, $limit);
    }

    #[\Override]
    public function findFailed(array $types = [], int $limit = 1000): array
    {
        return $this->findByStatus(OutboxStatus::Failed, $types, $limit);
    }

    /**
     * @param list<string> $types
     *
     * @return list<OutboxMessage>
     */
    private function findByStatus(OutboxStatus $status, array $types, int $limit): array
    {
        $query = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['status' => $status->value])
            ->orderBy(['created_at' => SORT_ASC])
            ->limit($limit);

        if ($types !== []) {
            $query->andWhere(['type' => $types]);
        }

        $messages = [];

        foreach ($query->all() as $row) {
            /** @var array<array-key, mixed> $row */
            $messages[] = $this->mapper->map(row: $row);
        }

        return $messages;
    }

    /**
     * One `UPDATE ... WHERE id = :id AND status = 'failed'`: the row's current
     * status decides, not the caller's snapshot, so a message a worker or
     * another operator moved meanwhile is left alone.
     */
    #[\Override]
    public function requeue(OutboxMessage $message): bool
    {
        $updated = $this->db->createCommand()->update(
            table: $this->table,
            columns: [
                'status' => OutboxStatus::Pending->value,
                'attempts' => 0,
                'last_attempt_at' => null,
                'claimed_by' => null,
                'claimed_at' => null,
            ],
            condition: ['id' => $message->getId(), 'status' => OutboxStatus::Failed->value],
        )->execute();

        return $updated > 0;
    }

    #[\Override]
    public function stats(): OutboxStats
    {
        $counts = [
            OutboxStatus::Pending->value => 0,
            OutboxStatus::Processing->value => 0,
            OutboxStatus::Published->value => 0,
            OutboxStatus::Failed->value => 0,
        ];
        $oldestPending = null;

        $rows = (new Query($this->db))
            ->select(['status', 'total' => 'COUNT(*)', 'oldest' => 'MIN(created_at)'])
            ->from($this->table)
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            /** @var array<array-key, mixed> $row */
            $status = $this->mapper->extractStatus(row: $row);
            $counts[$status->value] = $this->mapper->extractInt(row: $row, column: 'total');

            if ($status === OutboxStatus::Pending) {
                $oldestPending = $this->mapper->extractNullableDateTime(row: $row, column: 'oldest');
            }
        }

        return new OutboxStats(
            pending: $counts[OutboxStatus::Pending->value],
            processing: $counts[OutboxStatus::Processing->value],
            published: $counts[OutboxStatus::Published->value],
            failed: $counts[OutboxStatus::Failed->value],
            oldestPendingCreatedAt: $oldestPending,
        );
    }

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->claimWhere($types, $limit, null);
    }

    #[\Override]
    public function claimReady(
        \DateTimeImmutable $readyThreshold,
        int $maxAttempts,
        array $types = [],
        int $limit = 1000,
    ): array {
        return $this->claimWhere($types, $limit, [
            'or',
            // Not an optimisation: an exhausted message can only ever be
            // marked Failed, and the caller can only fail what this method
            // returned. Leave it out and nothing terminates it — it stays
            // Pending forever, invisible to an alert watching Failed.
            ['>=', 'attempts', $maxAttempts],
            ['last_attempt_at' => null],
            ['<=', 'last_attempt_at', $this->mapper->formatDateTime($readyThreshold)],
        ]);
    }

    /**
     * The claim both public entry points run, with an optional extra predicate
     * on which rows are eligible.
     *
     * @param list<string> $types
     * @param array<array-key, mixed>|null $eligible
     *
     * @return list<OutboxMessage>
     */
    private function claimWhere(array $types, int $limit, ?array $eligible): array
    {
        return $this->db->transaction(function () use ($types, $limit, $eligible): array {
            $query = (new Query($this->db))
                ->select('id')
                ->from($this->table)
                ->where(condition: ['status' => OutboxStatus::Pending->value])
                ->orderBy(['created_at' => SORT_ASC])
                ->limit($limit);

            if ($types !== []) {
                $query->andWhere(['type' => $types]);
            }

            if ($eligible !== null) {
                $query->andWhere($eligible);
            }

            $ids = $query->column();

            if ($ids === []) {
                return [];
            }

            $claimId = bin2hex(random_bytes(8));

            $this->db->createCommand()->update(
                table: $this->table,
                columns: [
                    'status' => OutboxStatus::Processing->value,
                    'claimed_by' => $claimId,
                    'claimed_at' => $this->mapper->formatDateTime($this->now()),
                ],
                condition: ['id' => $ids, 'status' => OutboxStatus::Pending->value],
            )->execute();

            $rows = (new Query($this->db))
                ->from($this->table)
                ->where(condition: ['claimed_by' => $claimId, 'status' => OutboxStatus::Processing->value])
                ->orderBy(['created_at' => SORT_ASC])
                ->all();

            $messages = [];

            foreach ($rows as $row) {
                /** @var array<array-key, mixed> $row */
                $messages[] = $this->mapper->map(row: $row);
            }

            return $messages;
        });
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        if ($this->deletePublished) {
            $this->deleteByIds([$message->getId()]);

            return;
        }

        $this->save(message: $message->withStatus(OutboxStatus::Published));
    }

    /**
     * No `status = 'processing'` guard on purpose. The acknowledgement comes
     * after a successful delivery and a row is immutable apart from its status,
     * so acknowledging it is right whatever state it is in — a guard would only
     * leave a row that `releaseStaleClaims()` put back to `Pending` in place,
     * and that is a guaranteed second delivery.
     */
    #[\Override]
    public function markPublishedBatch(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        if ($this->deletePublished) {
            $this->deleteByIds(array_map(static fn(OutboxMessage $message): string => $message->getId(), $messages));

            return;
        }

        // One UPDATE per distinct (attempts, last_attempt_at) pair, so each row
        // ends up exactly as markPublished() would have left it. A batch
        // acknowledged by one consumer shares the attempt stamp, so in practice
        // that is one statement.
        /** @var array<int, array<string, non-empty-list<string>>> $batches attempts → last_attempt_at ('' for none) → ids */
        $batches = [];

        foreach ($messages as $message) {
            $lastAttemptAt = $message->getLastAttemptAt();
            $stamp = $lastAttemptAt === null ? '' : $this->mapper->formatDateTime($lastAttemptAt);
            $batches[$message->getAttempts()][$stamp][] = $message->getId();
        }

        foreach ($batches as $attempts => $byStamp) {
            foreach ($byStamp as $stamp => $ids) {
                $this->db->createCommand()->update(
                    table: $this->table,
                    columns: [
                        'status' => OutboxStatus::Published->value,
                        'attempts' => $attempts,
                        'last_attempt_at' => $stamp === '' ? null : $stamp,
                        'claimed_by' => null,
                        'claimed_at' => null,
                    ],
                    condition: ['id' => $ids],
                )->execute();
            }
        }
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->save(message: $message->withStatus(OutboxStatus::Failed));
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['id' => $id])
            ->one();

        if ($row === null) {
            return null;
        }

        /** @var array<array-key, mixed> $row */
        return $this->mapper->map(row: $row);
    }

    /**
     * Messages still `Processing` whose claim is older than `$claimedBefore` —
     * the ones a worker took and never finished, because it was killed, ran out
     * of memory or hit an exception nothing caught.
     *
     * A `Processing` row with no timestamp counts as stale. Two things produce
     * one: a worker from a version that predates the column, and a caller that
     * passed a `Processing` message to {@see self::save()}, which always clears
     * `claimed_by` and `claimed_at`. Neither is owned by a live claim — that is
     * what the missing `claimed_by` says — so releasing them is right in both
     * cases.
     *
     * @param positive-int $limit
     *
     * @return list<OutboxMessage>
     */
    public function findStaleClaims(\DateTimeImmutable $claimedBefore, int $limit = 1000): array
    {
        $messages = [];

        foreach ($this->staleQuery($claimedBefore)->limit($limit)->all() as $row) {
            /** @var array<array-key, mixed> $row */
            $messages[] = $this->mapper->map(row: $row);
        }

        return $messages;
    }

    /**
     * Puts stale claims back to `Pending` so the next `claim()` picks them up,
     * and returns how many rows were released.
     *
     * Attempts are left untouched: the message was never actually attempted by
     * the worker that died, and {@see \Rasuvaeff\Yii3Outbox\RetryPolicy} still
     * caps how many times it can come back.
     *
     * Run it from a cron or a supervisor hook with a threshold comfortably
     * longer than the slowest batch — releasing a claim a live worker still
     * holds means the message is delivered twice, which the at-least-once
     * contract allows but nobody enjoys.
     *
     * @param positive-int $limit
     */
    public function releaseStaleClaims(\DateTimeImmutable $claimedBefore, int $limit = 1000): int
    {
        return $this->db->transaction(function () use ($claimedBefore, $limit): int {
            /** @var list<string> $ids */
            $ids = $this->staleQuery($claimedBefore)->select('id')->limit($limit)->column();

            if ($ids === []) {
                return 0;
            }

            // The staleness predicate is repeated here on purpose. Selecting
            // the ids and updating them are two statements, and between them a
            // concurrent recovery can release a row and a worker can claim it
            // again with a fresh timestamp. Updating on the id list alone would
            // then reset that live claim to Pending and hand the message to a
            // second worker while the first is still delivering it.
            return $this->db->createCommand()->update(
                table: $this->table,
                columns: ['status' => OutboxStatus::Pending->value, 'claimed_by' => null, 'claimed_at' => null],
                condition: [
                    'and',
                    ['id' => $ids],
                    ['status' => OutboxStatus::Processing->value],
                    $this->staleCondition($claimedBefore),
                ],
            )->execute();
        });
    }

    /**
     * Deletes every row in the given status — or, with $olderThan, only those
     * created before that moment, so a `Published` purge can keep the recent
     * rows an audit or a deduplication check may still want to see.
     *
     * @return int rows deleted
     */
    public function deleteByStatus(OutboxStatus $status, ?\DateTimeImmutable $olderThan = null): int
    {
        $condition = ['status' => $status->value];

        if ($olderThan !== null) {
            $condition = ['and', $condition, ['<', 'created_at', $this->mapper->formatDateTime($olderThan)]];
        }

        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: $condition,
        )->execute();
    }

    /**
     * @param non-empty-list<string> $ids
     */
    private function deleteByIds(array $ids): void
    {
        $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['id' => $ids],
        )->execute();
    }

    private function staleQuery(\DateTimeImmutable $claimedBefore): Query
    {
        return (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['status' => OutboxStatus::Processing->value])
            ->andWhere($this->staleCondition($claimedBefore))
            ->orderBy(['created_at' => SORT_ASC]);
    }

    /**
     * @return array{0: string, 1: array{claimed_at: null}, 2: array{0: string, 1: string, 2: string}}
     */
    private function staleCondition(\DateTimeImmutable $claimedBefore): array
    {
        return [
            'or',
            ['claimed_at' => null],
            ['<', 'claimed_at', $this->mapper->formatDateTime($claimedBefore)],
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function toColumns(OutboxMessage $message): array
    {
        $lastAttemptAt = $message->getLastAttemptAt();

        return [
            'id' => $message->getId(),
            'type' => $message->getType(),
            'payload' => $message->getPayload(),
            'status' => $message->getStatus()->value,
            'created_at' => $this->mapper->formatDateTime($message->getCreatedAt()),
            'attempts' => $message->getAttempts(),
            'last_attempt_at' => $lastAttemptAt === null ? null : $this->mapper->formatDateTime($lastAttemptAt),
            'aggregate_id' => $message->getAggregateId(),
            'claimed_by' => null,
            'claimed_at' => null,
        ];
    }
}
