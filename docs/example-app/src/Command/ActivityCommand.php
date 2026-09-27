<?php

declare(strict_types=1);

namespace App\Command;

use App\Task\Query\ListActivity;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * Shows what the event handlers did once the relay handed them the events of app:demo.
 */
#[AsCommand(name: 'app:activity', description: 'Show what the event handlers did (after "somework:cqrs:outbox:relay").')]
final class ActivityCommand extends Command
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $activity = $this->queryBus->ask(new ListActivity());

        if ([] === $activity) {
            $io->warning('No event was handled yet: run "php bin/console somework:cqrs:outbox:relay".');

            return Command::FAILURE;
        }

        $io->listing($activity);
        $io->success(sprintf('The relay handed %d event(s) to their handlers.', count($activity)));

        return Command::SUCCESS;
    }
}
