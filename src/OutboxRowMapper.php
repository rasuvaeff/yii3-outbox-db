<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;

/**
 * @internal
 */
final readonly class OutboxRowMapper
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<array-key, mixed> $row
     */
    public function map(array $row): OutboxMessage
    {
        $id = $this->extractString(row: $row, column: 'id');
        $type = $this->extractString(row: $row, column: 'type');
        $payload = $this->extractString(row: $row, column: 'payload');
        $status = $this->extractStatus(row: $row);
        $createdAt = $this->parseDateTime(value: $this->extractString(row: $row, column: 'created_at'), column: 'created_at');
        $attempts = $this->extractInt(row: $row, column: 'attempts');
        $lastAttemptAt = $this->extractNullableDateTime(row: $row, column: 'last_attempt_at');
        $aggregateId = $this->extractNullableString(row: $row, column: 'aggregate_id');

        try {
            return new OutboxMessage(
                id: $id,
                type: $type,
                payload: $payload,
                status: $status,
                createdAt: $createdAt,
                attempts: $attempts,
                lastAttemptAt: $lastAttemptAt,
                aggregateId: $aggregateId,
            );
        } catch (\InvalidArgumentException $e) {
            throw new InvalidOutboxRowException(
                message: sprintf('Invalid outbox row: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * Serializes a UTC datetime in the storage format.
     */
    public function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::DATETIME_FORMAT);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractStatus(array $row): OutboxStatus
    {
        $value = $this->extractString(row: $row, column: 'status');
        $status = OutboxStatus::tryFrom($value);

        if ($status === null) {
            throw new InvalidOutboxRowException(
                message: sprintf('Invalid outbox status "%s"', $value),
            );
        }

        return $status;
    }

    private function parseDateTime(string $value, string $column): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new InvalidOutboxRowException(
                message: sprintf('Invalid "%s" datetime: %s', $column, $value),
                previous: $e,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractNullableDateTime(array $row, string $column): ?\DateTimeImmutable
    {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            return null;
        }

        if (!\is_string($row[$column])) {
            throw new InvalidOutboxRowException(
                message: sprintf('Invalid column "%s": expected string or null, got %s', $column, get_debug_type($row[$column])),
            );
        }

        return $this->parseDateTime(value: $row[$column], column: $column);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractString(array $row, string $column): string
    {
        if (!isset($row[$column]) || !\is_string($row[$column])) {
            throw new InvalidOutboxRowException(
                message: sprintf('Missing or invalid column "%s" in outbox row', $column),
            );
        }

        return $row[$column];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractNullableString(array $row, string $column): ?string
    {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            return null;
        }

        if (!\is_string($row[$column])) {
            throw new InvalidOutboxRowException(
                message: sprintf('Invalid column "%s": expected string or null, got %s', $column, get_debug_type($row[$column])),
            );
        }

        return $row[$column];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractInt(array $row, string $column): int
    {
        if (!isset($row[$column])) {
            throw new InvalidOutboxRowException(
                message: sprintf('Missing or invalid column "%s" in outbox row', $column),
            );
        }

        if (\is_int($row[$column])) {
            return $row[$column];
        }

        if (\is_string($row[$column]) && preg_match('/^-?\d+$/', $row[$column]) === 1) {
            return (int) $row[$column];
        }

        throw new InvalidOutboxRowException(
            message: sprintf('Missing or invalid column "%s" in outbox row', $column),
        );
    }
}
