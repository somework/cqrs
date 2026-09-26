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
    description: 'Create the outbox table if it does not exist.',
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

        $this->outboxStorage->setup();

        $io->success('The outbox table is ready.');

        return self::SUCCESS;
    }
}
