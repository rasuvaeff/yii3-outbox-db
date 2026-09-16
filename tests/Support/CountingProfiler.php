<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Support;

use Yiisoft\Db\Profiler\ContextInterface;
use Yiisoft\Db\Profiler\ProfilerInterface;

/**
 * Counts the statements a connection executes, so a test can claim "one
 * statement for the whole batch" rather than infer it from the outcome.
 */
final class CountingProfiler implements ProfilerInterface
{
    public int $statements = 0;

    #[\Override]
    public function begin(string $token, array|ContextInterface $context = []): void
    {
        $this->statements++;
    }

    #[\Override]
    public function end(string $token, array|ContextInterface $context = []): void {}
}
