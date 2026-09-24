<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:setup',
    description: 'Create the outbox table, or add the columns a table of an earlier version lacks.',
)]
final class OutboxSetupCommand extends Command
{
    public function __construct(private readonly OutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->outboxStorage instanceof DbalOutboxStorage) {
            $io->error(sprintf('The outbox storage (%s) is not the DBAL storage; create its schema yourself.', $this->outboxStorage::class));

            return self::FAILURE;
        }

        try {
            $this->outboxStorage->setup();
        } catch (\Throwable $exception) {
            // e.g. the database is down, or the table cannot be changed: exit with 1 and say why.
            $io->error(sprintf('The outbox table could not be set up: %s', $exception->getMessage()));

            return self::FAILURE;
        }

        $io->success('The outbox table is ready.');

        return self::SUCCESS;
    }
}
