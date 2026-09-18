<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Console;

use Rasuvaeff\Yii3Outbox\RequeueableStorageInterface;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts `Failed` messages back in line once their cause is fixed, through
 * {@see RequeueableStorageInterface} — every `Failed` row of the given types
 * (all types by default), up to `--limit`, becomes `Pending` with its
 * attempts reset.
 *
 * @api
 */
#[AsCommand(name: 'outbox:requeue', description: 'Move Failed outbox messages back to Pending with their attempts reset')]
final class RequeueFailedOutboxCommand extends Command
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only messages of this type (repeatable); all types when omitted')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Requeue at most this many messages', '1000');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->storage instanceof RequeueableStorageInterface) {
            $output->writeln(sprintf('<error>outbox:requeue needs a %s, got %s</error>', RequeueableStorageInterface::class, $this->storage::class));

            return Command::INVALID;
        }

        $limit = Limit::parse($input->getOption('limit'));

        if ($limit === null) {
            $output->writeln('<error>--limit must be a positive integer</error>');

            return Command::INVALID;
        }

        /** @var list<string> $types */
        $types = array_values(array_filter(
            (array) $input->getOption('type'),
            static fn(mixed $type): bool => \is_string($type) && $type !== '',
        ));

        $requeued = 0;

        foreach ($this->storage->findFailed($types, $limit) as $message) {
            if ($this->storage->requeue($message)) {
                $requeued++;
            }
        }

        $output->writeln(sprintf('Requeued %d failed outbox message(s)', $requeued));

        return Command::SUCCESS;
    }
}
