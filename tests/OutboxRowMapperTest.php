<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxDb\Exception\InvalidOutboxRowException;
use Rasuvaeff\Yii3OutboxDb\OutboxRowMapper;

#[CoversClass(OutboxRowMapper::class)]
final class OutboxRowMapperTest extends TestCase
{
    private OutboxRowMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new OutboxRowMapper();
    }

    #[Test]
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

        $this->assertSame('id-1', $message->getId());
        $this->assertSame('ab.exposure', $message->getType());
        $this->assertSame('{"experiment":"x"}', $message->getPayload());
        $this->assertSame(OutboxStatus::Pending, $message->getStatus());
        $this->assertSame('2026-06-11 12:00:00', $message->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $message->getAttempts());
        $this->assertNotNull($message->getLastAttemptAt());
        $this->assertSame('2026-06-11 12:05:00', $message->getLastAttemptAt()->format('Y-m-d H:i:s'));
        $this->assertSame('agg-1', $message->getAggregateId());
    }

    #[Test]
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

        $this->assertNull($message->getLastAttemptAt());
        $this->assertNull($message->getAggregateId());
        $this->assertSame(OutboxStatus::Published, $message->getStatus());
    }

    #[Test]
    public function parsesIntAttemptsFromString(): void
    {
        $message = $this->mapper->map($this->validRow(['attempts' => '3']));

        $this->assertSame(3, $message->getAttempts());
    }

    #[Test]
    public function throwsOnInvalidStatus(): void
    {
        $this->expectException(InvalidOutboxRowException::class);
        $this->expectExceptionMessage('Invalid outbox status "weird"');

        $this->mapper->map($this->validRow(['status' => 'weird']));
    }

    #[Test]
    public function throwsOnInvalidCreatedAt(): void
    {
        $this->expectException(InvalidOutboxRowException::class);
        $this->expectExceptionMessage('Invalid "created_at" datetime');

        $this->mapper->map($this->validRow(['created_at' => 'not-a-date']));
    }

    #[Test]
    public function throwsOnInvalidLastAttemptAt(): void
    {
        $this->expectException(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow(['last_attempt_at' => 'broken']));
    }

    #[Test]
    public function throwsOnNonNumericAttempts(): void
    {
        $this->expectException(InvalidOutboxRowException::class);

        $this->mapper->map($this->validRow(['attempts' => 'x']));
    }

    #[Test]
    #[DataProvider('missingColumnProvider')]
    public function throwsOnMissingRequiredColumn(string $column): void
    {
        $row = $this->validRow();
        unset($row[$column]);

        $this->expectException(InvalidOutboxRowException::class);

        $this->mapper->map($row);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingColumnProvider(): iterable
    {
        yield 'id' => ['id'];
        yield 'type' => ['type'];
        yield 'payload' => ['payload'];
        yield 'status' => ['status'];
        yield 'created_at' => ['created_at'];
        yield 'attempts' => ['attempts'];
    }

    #[Test]
    public function throwsOnEmptyId(): void
    {
        $this->expectException(InvalidOutboxRowException::class);
        $this->expectExceptionMessage('Invalid outbox row');

        $this->mapper->map($this->validRow(['id' => '']));
    }

    #[Test]
    public function formatDateTimeNormalizesToUtc(): void
    {
        $formatted = $this->mapper->formatDateTime(
            new \DateTimeImmutable('2026-06-11 15:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        $this->assertSame('2026-06-11 13:00:00', $formatted);
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
