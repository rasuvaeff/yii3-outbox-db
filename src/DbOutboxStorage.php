<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * SQL-backed {@see StorageInterface}.
 *
 * Beyond the interface it exposes two operational helpers that no storage
 * contract can express: {@see self::deleteByStatus()} for retention, and
 * {@see self::findStaleClaims()} / {@see self::releaseStaleClaims()} for the
 * rows a killed worker leaves behind in `Processing`.
 *
 * @api
 */
final readonly class DbOutboxStorage implements StorageInterface
{
    private OutboxRowMapper $mapper;

    private string $table;

    /**
     * @param non-empty-string $table
     *
     * @throws \InvalidArgumentException when the name is not a valid identifier
     */
    public function __construct(
        private ConnectionInterface $db,
        string $table = 'outbox',
        private ?ClockInterface $clock = null,
    ) {
        // validation lives in the value object, so the storage and the bundled
        // migration cannot disagree about what a valid table name is
        $this->table = (new OutboxTableName($table))->value;
        $this->mapper = new OutboxRowMapper();
    }

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->db->createCommand()->upsert(
            table: $this->table,
            insertColumns: $this->toColumns(message: $message),
        )->execute();
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        $query = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['status' => OutboxStatus::Pending->value])
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

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->db->transaction(function () use ($types, $limit): array {
            $query = (new Query($this->db))
                ->select('id')
                ->from($this->table)
                ->where(condition: ['status' => OutboxStatus::Pending->value])
                ->orderBy(['created_at' => SORT_ASC])
                ->limit($limit);

            if ($types !== []) {
                $query->andWhere(['type' => $types]);
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
        $this->save(message: $message->withStatus(OutboxStatus::Published));
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

    public function deleteByStatus(OutboxStatus $status): int
    {
        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['status' => $status->value],
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
