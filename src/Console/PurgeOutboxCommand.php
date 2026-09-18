<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Console;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retention: deletes `Published` (or `Failed`) rows, optionally only those
 * older than an age, through {@see DbOutboxStorage::deleteByStatus()}.
 *
 * `Pending` and `Processing` rows are never a purge target — the former are
 * work not yet done, the latter belong to a worker (or to
 * `outbox:release-stale`).
 *
 * @api
 */
#[AsCommand(name: 'outbox:purge', description: 'Delete published (or failed) outbox rows, optionally only those older than an age')]
final class PurgeOutboxCommand extends Command
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
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'published or failed', 'published')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only rows created more than this long ago, e.g. 7d, 12h, 30m');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->storage instanceof DbOutboxStorage) {
            $output->writeln(sprintf('<error>outbox:purge needs %s, got %s</error>', DbOutboxStorage::class, $this->storage::class));

            return Command::INVALID;
        }

        $statusOption = $input->getOption('status');

        if (!is_string($statusOption)) {
            return $this->rejectStatus($output);
        }

        $status = OutboxStatus::tryFrom($statusOption);

        if ($status !== OutboxStatus::Published && $status !== OutboxStatus::Failed) {
            return $this->rejectStatus($output);
        }

        $olderThanOption = $input->getOption('older-than');
        $olderThan = null;

        if ($olderThanOption !== null) {
            if (!is_string($olderThanOption)) {
                return $this->rejectAge($output, 'expected a value such as 7d');
            }

            try {
                $olderThan = Age::parse($olderThanOption)->before($this->now());
            } catch (InvalidArgumentException $e) {
                return $this->rejectAge($output, $e->getMessage());
            }
        }

        $deleted = $this->storage->deleteByStatus($status, $olderThan);

        $output->writeln(sprintf('Deleted %d %s outbox row(s)', $deleted, $status->value));

        return Command::SUCCESS;
    }

    private function rejectStatus(OutputInterface $output): int
    {
        $output->writeln('<error>--status must be "published" or "failed"</error>');

        return Command::INVALID;
    }

    private function rejectAge(OutputInterface $output, string $reason): int
    {
        $output->writeln(sprintf('<error>--older-than: %s</error>', $reason));

        return Command::INVALID;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
