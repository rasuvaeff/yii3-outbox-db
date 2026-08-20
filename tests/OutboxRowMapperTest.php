<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
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
#[Covers(InvalidOutboxRowException::class)]
final class OutboxRowMapperTest
{
    private OutboxRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new OutboxRowMapper();
    }

    /**
     * The mapper and the storage's column writer are two halves of one
     * round-trip: every message the storage writes must come back from
     * `map()` unchanged. Datetimes are the interesting half — they are stored
     * as UTC strings with second precision, so the property asserts what
     * survives that, not object identity.
     *
     * @param array{id: string, type: string, payload: string, attempts: int, aggregateId: string|null} $fields
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function everyStoredMessageMapsBackToItself(array $fields, OutboxStatus $status, \DateTimeImmutable $createdAt, ?\DateTimeImmutable $lastAttemptAt): void
    {
        $message = new OutboxMessage(
            id: $fields['id'],
            type: $fields['type'],
            payload: $fields['payload'],
            status: $status,
            createdAt: $createdAt,
            attempts: $fields['attempts'],
            lastAttemptAt: $lastAttemptAt,
            aggregateId: $fields['aggregateId'],
        );

        Classify::cover($lastAttemptAt === null, 'never attempted', 20.0);
        Classify::cover($fields['aggregateId'] === null, 'no aggregate id', 20.0);
        Classify::when($fields['attempts'] === 0, 'zero attempts');

        // Exactly the row DbOutboxStorage::toColumns() writes.
        $mapped = $this->mapper->map([
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
        ]);

        Assert::same($mapped->getId(), $message->getId());
        Assert::same($mapped->getType(), $message->getType());
        Assert::same($mapped->getPayload(), $message->getPayload());
        Assert::same($mapped->getStatus(), $message->getStatus());
        Assert::same($mapped->getAttempts(), $message->getAttempts());
        Assert::same($mapped->getAggregateId(), $message->getAggregateId());
        Assert::same(
            $mapped->getCreatedAt()->getTimestamp(),
            $message->getCreatedAt()->getTimestamp(),
        );
        Assert::same(
            $mapped->getLastAttemptAt()?->getTimestamp(),
            $lastAttemptAt?->getTimestamp(),
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyStoredMessageMapsBackToItselfGenerators(): array
    {
        return [
            'fields' => Gen::record([
                'id' => Gen::stringFrom('abcdef0123456789-', minLength: 1, maxLength: 40),
                'type' => Gen::elements(['ab.exposure', 'order.created', 'user.registered']),
                // Payloads are opaque strings to the mapper: JSON, an empty
                // object, or something that is not JSON at all.
                'payload' => Gen::frequency([
                    [3, Gen::jsonString()],
                    [1, Gen::constant('{}')],
                    [1, Gen::stringAscii()],
                ]),
                'attempts' => Gen::intBetween(0, 10),
                'aggregateId' => Gen::nullable(Gen::stringFrom('abcdef0123456789-', minLength: 1, maxLength: 20)),
            ]),
            'status' => Gen::enum(OutboxStatus::class),
            'createdAt' => Gen::datetime(),
            'lastAttemptAt' => Gen::nullable(Gen::datetime()),
        ];
    }

    /** @return iterable<string, array{array{id: string, type: string, payload: string, attempts: int, aggregateId: string|null}, OutboxStatus, \DateTimeImmutable, ?\DateTimeImmutable}> */
    public static function everyStoredMessageMapsBackToItselfExamples(): iterable
    {
        yield 'freshly recorded message' => [
            ['id' => 'a', 'type' => 'ab.exposure', 'payload' => '{}', 'attempts' => 0, 'aggregateId' => null],
            OutboxStatus::Pending,
            new \DateTimeImmutable('2026-08-20 10:00:00', new \DateTimeZone('UTC')),
            null,
        ];
        yield 'non-utc input is stored as utc' => [
            ['id' => 'b', 'type' => 'order.created', 'payload' => '{"a":1}', 'attempts' => 2, 'aggregateId' => 'agg'],
            OutboxStatus::Failed,
            new \DateTimeImmutable('2026-08-20 10:00:00', new \DateTimeZone('Europe/Moscow')),
            new \DateTimeImmutable('2026-08-20 12:00:00', new \DateTimeZone('America/New_York')),
        ];
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

    public function throwsOnAttemptsWithEmbeddedDigits(): void
    {
        Expect::exception(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow(['attempts' => 'x3']));
    }

    #[DataProvider('nonStringColumnProvider')]
    public function throwsOnNonStringColumn(string $column): void
    {
        Expect::exception(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow([$column => 123]));
    }

    public static function nonStringColumnProvider(): iterable
    {
        yield 'id' => ['id'];
        yield 'type' => ['type'];
        yield 'payload' => ['payload'];
        yield 'status' => ['status'];
        yield 'created_at' => ['created_at'];
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
