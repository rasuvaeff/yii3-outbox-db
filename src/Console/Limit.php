<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Console;

/**
 * The `--limit` option value: a positive integer (leading zeros allowed, as
 * in `Age`), or null when it is not.
 *
 * @internal
 */
final readonly class Limit
{
    /**
     * @return ?positive-int
     */
    public static function parse(mixed $value): ?int
    {
        if (!\is_string($value) || preg_match('/^\d{1,9}\z/', $value) !== 1) {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }
}
