<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Console;

use InvalidArgumentException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3OutboxDb\Console\Age;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Age::class)]
final class AgeTest
{
    #[DataProvider('validProvider')]
    public function parsesEveryUnit(string $value, int $seconds): void
    {
        Assert::same(Age::parse($value)->seconds, $seconds);
    }

    public static function validProvider(): iterable
    {
        yield 'seconds' => ['45s', 45];
        yield 'minutes' => ['15m', 900];
        yield 'hours' => ['2h', 7200];
        yield 'days' => ['7d', 604_800];
        yield 'one second' => ['1s', 1];
        yield 'nine digits' => ['999999999s', 999_999_999];
    }

    #[DataProvider('invalidProvider')]
    public function rejectsAnythingElse(string $value, string $message): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        Age::parse($value);
    }

    public static function invalidProvider(): iterable
    {
        yield 'empty' => ['', 'expected a positive integer followed by s, m, h or d'];
        yield 'no unit' => ['15', 'expected a positive integer'];
        yield 'unknown unit' => ['15w', 'expected a positive integer'];
        yield 'negative' => ['-5m', 'expected a positive integer'];
        yield 'zero' => ['0m', 'the count must be positive'];
        yield 'all zeros' => ['000d', 'the count must be positive'];
        yield 'fraction' => ['1.5h', 'expected a positive integer'];
        yield 'space' => ['15 m', 'expected a positive integer'];
        yield 'trailing newline' => ["15m\n", 'expected a positive integer'];
        yield 'too many digits' => ['1000000000s', 'expected a positive integer'];
    }

    public function beforeSubtractsTheAge(): void
    {
        $now = new \DateTimeImmutable('2026-06-11 12:00:00');

        Assert::same(Age::parse('15m')->before($now)->format('Y-m-d H:i:s'), '2026-06-11 11:45:00');
        Assert::same(Age::parse('7d')->before($now)->format('Y-m-d H:i:s'), '2026-06-04 12:00:00');
        Assert::same($now->format('Y-m-d H:i:s'), '2026-06-11 12:00:00');
    }

    /**
     * Every `<count><unit>` string parses to count × unit seconds, and `before()`
     * is exactly that far back — the regex accepts what the grammar says and
     * nothing else.
     */
    #[Property(runs: 200)]
    public function parsesCountTimesUnit(int $count, string $unit): void
    {
        $age = Age::parse($count . $unit);
        $expected = $count * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$unit];

        Assert::same($age->seconds, $expected);

        $now = new \DateTimeImmutable('2026-06-11 12:00:00');
        Assert::same($now->getTimestamp() - $age->before($now)->getTimestamp(), $expected);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function parsesCountTimesUnitGenerators(): array
    {
        return [
            // a hundred thousand days is 273 years: past that `modify()` runs
            // into DateTime's own range, which is not what this checks
            'count' => Gen::intBetween(1, 100_000),
            'unit' => Gen::elements(['s', 'm', 'h', 'd']),
        ];
    }

    /**
     * Accept ⇔ the grammar: strings from the option's alphabet are parsed
     * exactly when they match `^[0-9]{1,9}[smhd]$` with a non-zero count.
     */
    #[Property(runs: 300)]
    public function acceptsExactlyTheGrammar(string $candidate): void
    {
        $matches = preg_match('/^(\d{1,9})([smhd])\z/', $candidate, $m) === 1 && (int) $m[1] > 0;

        try {
            Age::parse($candidate);
            $accepted = true;
        } catch (InvalidArgumentException) {
            $accepted = false;
        }

        Assert::same($accepted, $matches);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyTheGrammarGenerators(): array
    {
        return ['candidate' => Gen::stringFrom('0123456789smhdw. -', maxLength: 12)];
    }
}
