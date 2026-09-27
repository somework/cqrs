<?php

declare(strict_types=1);

namespace App\Command;

use App\Task\Command\CompleteTask;
use App\Task\Command\CreateTask;
use App\Task\Query\FindTaskById;
use App\Task\Query\ListTasks;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function sprintf;

/**
 * Walks through the task domain: dispatches commands, whose entities record events that wait in
 * the outbox, and reads the result back through queries.
 */
#[AsCommand(name: 'app:demo', description: 'Dispatch commands, store their events in the outbox and ask queries through the CQRS buses.')]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly OutboxMonitoring $outbox,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('somework/cqrs-bundle demo');

        if (null !== $this->queryBus->ask(new FindTaskById('task-1'))) {
            $io->error('The demo already ran on this database. Start over with: rm -f var/data.db && php bin/console doctrine:schema:create');

            return Command::FAILURE;
        }

        $io->section('Commands');

        // dispatch() uses the configured dispatch mode (sync here) and returns the Messenger envelope.
        $envelope = $this->commandBus->dispatch(new CreateTask('task-1', 'Write the documentation'));
        $metadata = $envelope->last(MessageMetadataStamp::class);
        $io->writeln(sprintf(
            'CreateTask(task-1) dispatched, correlation id: %s',
            $metadata instanceof MessageMetadataStamp ? $metadata->getCorrelationId() : 'n/a',
        ));

        // dispatchSync() handles the command right away and returns the handler result.
        $this->commandBus->dispatchSync(new CreateTask('task-2', 'Release version 0.6.0'));
        $io->writeln('CreateTask(task-2) handled');

        $this->commandBus->dispatchSync(new CompleteTask('task-1'));
        $io->writeln('CompleteTask(task-1) handled');

        $io->section('Events');
        // Each command ran in a transaction ("doctrine_transaction"): the flush wrote the task and
        // stored the events it recorded in the outbox; nothing handled them yet.
        $waiting = $this->outbox->status()->due;
        $io->writeln(sprintf('The Task entity recorded %d event(s); they were stored in the outbox with the changes, and wait for the relay:', $waiting));
        $io->writeln('  php bin/console somework:cqrs:outbox:relay   # hands them to their handlers');
        $io->writeln('  php bin/console app:activity                 # shows what the handlers did');

        $io->section('Queries');

        $tasks = $this->queryBus->ask(new ListTasks());
        $io->writeln('ListTasks:');
        $io->table(
            ['ID', 'Title', 'Status'],
            array_map(
                static fn (array $task): array => [$task['id'], $task['title'], $task['completed'] ? 'done' : 'open'],
                $tasks,
            ),
        );

        $task = $this->queryBus->ask(new FindTaskById('task-2'));
        $io->writeln(null === $task
            ? 'FindTaskById(task-2): not found'
            : sprintf('FindTaskById(task-2): "%s" (%s)', $task['title'], $task['completed'] ? 'done' : 'open'));

        $io->success(sprintf('Created %d tasks, completed 1; %d event(s) wait in the outbox.', count($tasks), $waiting));

        return Command::SUCCESS;
    }
}
