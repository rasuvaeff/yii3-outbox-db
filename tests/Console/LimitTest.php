<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Console;

use Rasuvaeff\Yii3OutboxDb\Console\Limit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Limit::class)]
final class LimitTest
{
    #[DataProvider('provider')]
    public function parsesPositiveIntegersOnly(mixed $value, ?int $expected): void
    {
        Assert::same(Limit::parse($value), $expected);
    }

    public static function provider(): iterable
    {
        yield 'one' => ['1', 1];
        yield 'thousand' => ['1000', 1000];
        yield 'nine digits' => ['999999999', 999_999_999];
        yield 'zero' => ['0', null];
        yield 'leading zero' => ['01', 1];
        yield 'zeros then zero' => ['000', null];
        yield 'negative' => ['-1', null];
        yield 'empty' => ['', null];
        yield 'not a string' => [1000, null];
        yield 'null' => [null, null];
        yield 'trailing newline' => ["10\n", null];
        yield 'ten digits' => ['1000000000', null];
    }
}
