<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests\Support;

use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Profiler\ContextInterface;
use Yiisoft\Db\Profiler\ProfilerInterface;

/**
 * Runs one statement on the same connection right before the Nth statement a
 * test executes, so a test can change a row between a storage method's SELECT
 * and its UPDATE without a second connection.
 */
final class RowChangingProfiler implements ProfilerInterface
{
    public bool $fired = false;

    private int $statements = 0;

    private bool $busy = false;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly int $afterStatements,
        private readonly string $sql,
    ) {}

    #[\Override]
    public function begin(string $token, array|ContextInterface $context = []): void
    {
        if ($this->busy) {
            return;
        }

        if ($this->statements === $this->afterStatements && !$this->fired) {
            $this->busy = true;
            $this->db->createCommand(sql: $this->sql)->execute();
            $this->fired = true;
            $this->busy = false;
        }

        $this->statements++;
    }

    #[\Override]
    public function end(string $token, array|ContextInterface $context = []): void {}
}
