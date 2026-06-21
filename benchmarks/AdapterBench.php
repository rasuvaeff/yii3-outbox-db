<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Benchmarks;

use Rasuvaeff\Yii3OutboxDb\OutboxRowMapper;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'with-attempts' => [self::class, 'mapWithAttempts'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function mapFresh(): mixed
    {
        return (new OutboxRowMapper())->map([
            'id' => 'msg-001',
            'type' => 'order.created',
            'payload' => '{"order_id":42}',
            'status' => 'pending',
            'created_at' => '2024-01-15 10:30:00',
            'attempts' => 0,
            'last_attempt_at' => null,
            'aggregate_id' => null,
        ]);
    }

    public static function mapWithAttempts(): mixed
    {
        return (new OutboxRowMapper())->map([
            'id' => 'msg-001',
            'type' => 'order.created',
            'payload' => '{"order_id":42,"customer_id":7,"total":199.99,"items":["sku-1","sku-2"]}',
            'status' => 'failed',
            'created_at' => '2024-01-15 10:30:00',
            'attempts' => 3,
            'last_attempt_at' => '2024-01-15 11:05:00',
            'aggregate_id' => 'order:42',
        ]);
    }
}
