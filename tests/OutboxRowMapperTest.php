<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests;

use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;
use Rasuvaeff\Yii3OutboxDb\OutboxRowMapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutboxRowMapper::class)]
final class OutboxRowMapperTest
{
    private OutboxRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new OutboxRowMapper();
    }

    public function mapsFullRow(): void
    {
        $message = $this->mapper->map([
            'id' => 'id-1',
            'type' => 'ab.exposure',
            'payload' => '{"experiment":"x"}',
            'status' => 'pending',
            'created_at' => '2026-06-11 12:00:00',
            'attempts' => 2,
            'last_attempt_at' => '2026-06-11 12:05:00',
            'aggregate_id' => 'agg-1',
        ]);

        Assert::same($message->getId(), 'id-1');
        Assert::same($message->getType(), 'ab.exposure');
        Assert::same($message->getPayload(), '{"experiment":"x"}');
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getCreatedAt()->format('Y-m-d H:i:s'), '2026-06-11 12:00:00');
        Assert::same($message->getAttempts(), 2);
        Assert::notNull($message->getLastAttemptAt());
        Assert::same($message->getLastAttemptAt()->format('Y-m-d H:i:s'), '2026-06-11 12:05:00');
        Assert::same($message->getAggregateId(), 'agg-1');
    }

    public function mapsRowWithNullOptionalColumns(): void
    {
        $message = $this->mapper->map([
            'id' => 'id-1',
            'type' => 'order.created',
            'payload' => '{}',
            'status' => 'published',
            'created_at' => '2026-06-11 12:00:00',
            'attempts' => 0,
            'last_attempt_at' => null,
            'aggregate_id' => null,
        ]);

        Assert::null($message->getLastAttemptAt());
        Assert::null($message->getAggregateId());
        Assert::same($message->getStatus(), OutboxStatus::Published);
    }

    public function parsesIntAttemptsFromString(): void
    {
        $message = $this->mapper->map($this->validRow(['attempts' => '3']));

        Assert::same($message->getAttempts(), 3);
    }

    public function throwsOnInvalidStatus(): void
    {
        try {
            $this->mapper->map($this->validRow(['status' => 'weird']));
            Assert::fail('Expected InvalidOutboxRowException');
        } catch (InvalidOutboxRowException $e) {
            Assert::string($e->getMessage())->contains('Invalid outbox status "weird"');
        }
    }

    public function throwsOnInvalidCreatedAt(): void
    {
        try {
            $this->mapper->map($this->validRow(['created_at' => 'not-a-date']));
            Assert::fail('Expected InvalidOutboxRowException');
        } catch (InvalidOutboxRowException $e) {
            Assert::string($e->getMessage())->contains('Invalid "created_at" datetime');
        }
    }

    public function throwsOnInvalidLastAttemptAt(): void
    {
        Expect::exception(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow(['last_attempt_at' => 'broken']));
    }

    public function throwsOnNonNumericAttempts(): void
    {
        Expect::exception(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow(['attempts' => 'x']));
    }

    #[DataProvider('missingColumnProvider')]
    public function throwsOnMissingRequiredColumn(string $column): void
    {
        $row = $this->validRow();
        unset($row[$column]);

        Expect::exception(InvalidOutboxRowException::class);

        $this->mapper->map($row);
    }

    public static function missingColumnProvider(): iterable
    {
        yield 'id' => ['id'];
        yield 'type' => ['type'];
        yield 'payload' => ['payload'];
        yield 'status' => ['status'];
        yield 'created_at' => ['created_at'];
        yield 'attempts' => ['attempts'];
    }

    public function throwsOnEmptyId(): void
    {
        try {
            $this->mapper->map($this->validRow(['id' => '']));
            Assert::fail('Expected InvalidOutboxRowException');
        } catch (InvalidOutboxRowException $e) {
            Assert::string($e->getMessage())->contains('Invalid outbox row');
        }
    }

    public function formatDateTimeNormalizesToUtc(): void
    {
        $formatted = $this->mapper->formatDateTime(
            new \DateTimeImmutable('2026-06-11 15:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        Assert::same($formatted, '2026-06-11 13:00:00');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'id-1',
            'type' => 'ab.exposure',
            'payload' => '{}',
            'status' => 'pending',
            'created_at' => '2026-06-11 12:00:00',
            'attempts' => 0,
            'last_attempt_at' => null,
            'aggregate_id' => null,
        ], $overrides);
    }
}
