<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:setup',
    description: 'Create the outbox table if it does not exist.',
)]
final class OutboxSetupCommand extends Command
{
    public function __construct(private readonly DbalOutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->outboxStorage->setup();

        (new SymfonyStyle($input, $output))->success('The outbox table is ready.');

        return self::SUCCESS;
    }
}
