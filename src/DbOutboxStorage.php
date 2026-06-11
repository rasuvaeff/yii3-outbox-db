<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * @api
 */
final readonly class DbOutboxStorage implements StorageInterface
{
    private OutboxRowMapper $mapper;

    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private ConnectionInterface $db,
        private string $table = 'outbox',
    ) {
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

    public function deleteByStatus(OutboxStatus $status): int
    {
        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['status' => $status->value],
        )->execute();
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
        ];
    }
}
