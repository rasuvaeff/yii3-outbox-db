<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Console;

use InvalidArgumentException;

/**
 * The `--older-than=7d` / `--claimed-before=15m` option value: a positive
 * integer and one of `s`, `m`, `h`, `d`.
 *
 * @internal
 */
final readonly class Age
{
    private const array UNIT_SECONDS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    /**
     * @param positive-int $seconds
     */
    private function __construct(public int $seconds) {}

    /**
     * @throws InvalidArgumentException when the value is not `<digits><s|m|h|d>` with a positive count
     */
    public static function parse(string $value): self
    {
        if (preg_match('/^(?<count>\d{1,9})(?<unit>[smhd])\z/', $value, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid age "%s": expected a positive integer followed by s, m, h or d, e.g. "15m" or "7d"',
                $value,
            ));
        }

        $count = (int) $matches['count'];

        if ($count < 1) {
            throw new InvalidArgumentException(sprintf('Invalid age "%s": the count must be positive', $value));
        }

        return new self($count * self::UNIT_SECONDS[$matches['unit']]);
    }

    public function before(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', $this->seconds));
    }
}
