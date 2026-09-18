<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Console;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the `Processing` rows a killed worker left behind back to `Pending`,
 * through {@see DbOutboxStorage::releaseStaleClaims()}. Run it on a schedule
 * with a threshold comfortably longer than the slowest batch.
 *
 * @api
 */
#[AsCommand(name: 'outbox:release-stale', description: 'Release outbox claims older than an age back to Pending')]
final class ReleaseStaleOutboxClaimsCommand extends Command
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('claimed-before', null, InputOption::VALUE_REQUIRED, 'Release claims older than this, e.g. 15m, 2h', '15m')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Release at most this many rows', '1000');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->storage instanceof DbOutboxStorage) {
            $output->writeln(sprintf('<error>outbox:release-stale needs %s, got %s</error>', DbOutboxStorage::class, $this->storage::class));

            return Command::INVALID;
        }

        $claimedBeforeOption = $input->getOption('claimed-before');

        if (!is_string($claimedBeforeOption)) {
            return $this->rejectAge($output, 'expected a value such as 15m');
        }

        try {
            $claimedBefore = Age::parse($claimedBeforeOption)->before($this->now());
        } catch (InvalidArgumentException $e) {
            return $this->rejectAge($output, $e->getMessage());
        }

        $limit = Limit::parse($input->getOption('limit'));

        if ($limit === null) {
            $output->writeln('<error>--limit must be a positive integer</error>');

            return Command::INVALID;
        }

        $released = $this->storage->releaseStaleClaims($claimedBefore, $limit);

        $output->writeln(sprintf('Released %d stale outbox claim(s)', $released));

        return Command::SUCCESS;
    }

    private function rejectAge(OutputInterface $output, string $reason): int
    {
        $output->writeln(sprintf('<error>--claimed-before: %s</error>', $reason));

        return Command::INVALID;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
