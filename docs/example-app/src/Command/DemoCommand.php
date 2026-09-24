<?php

declare(strict_types=1);

namespace App\Command;

use App\Task\Command\CompleteTask;
use App\Task\Command\CreateTask;
use App\Task\Query\FindTaskById;
use App\Task\Query\ListTasks;
use App\Task\TaskActivityLog;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks through the task domain: dispatches commands, shows the events they raised
 * and reads the result back through queries.
 */
#[AsCommand(name: 'app:demo', description: 'Dispatch commands, handle events and ask queries through the CQRS buses.')]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly TaskActivityLog $activityLog,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('somework/cqrs-bundle demo');

        $io->section('Commands');

        // dispatch() uses the configured dispatch mode (sync here) and returns the Messenger envelope.
        $envelope = $this->commandBus->dispatch(new CreateTask('task-1', 'Write the documentation'));
        $metadata = $envelope->last(MessageMetadataStamp::class);
        $io->writeln(\sprintf(
            'CreateTask(task-1) dispatched, correlation id: %s',
            $metadata instanceof MessageMetadataStamp ? $metadata->getCorrelationId() : 'n/a',
        ));

        // dispatchSync() handles the command right away and returns the handler result.
        $this->commandBus->dispatchSync(new CreateTask('task-2', 'Release version 0.5.0'));
        $io->writeln('CreateTask(task-2) handled');

        $this->commandBus->dispatchSync(new CompleteTask('task-1'));
        $io->writeln('CompleteTask(task-1) handled');

        $io->section('Events');
        if ([] === $this->activityLog->entries()) {
            // Happens when TaskCreated is routed to an async transport (see somework_cqrs.yaml).
            $io->writeln('No events handled in this process; they were sent to a transport for a worker.');
        } else {
            $io->listing($this->activityLog->entries());
        }

        $io->section('Queries');

        /** @var list<array{id: string, title: string, completed: bool}> $tasks */
        $tasks = $this->queryBus->ask(new ListTasks());
        $io->writeln('ListTasks:');
        $io->table(
            ['ID', 'Title', 'Status'],
            array_map(
                static fn (array $task): array => [$task['id'], $task['title'], $task['completed'] ? 'done' : 'open'],
                $tasks,
            ),
        );

        /** @var array{id: string, title: string, completed: bool}|null $task */
        $task = $this->queryBus->ask(new FindTaskById('task-2'));
        $io->writeln(null === $task
            ? 'FindTaskById(task-2): not found'
            : \sprintf('FindTaskById(task-2): "%s" (%s)', $task['title'], $task['completed'] ? 'done' : 'open'));

        $io->success(\sprintf('Created %d tasks, completed 1, handled %d event(s).', \count($tasks), \count($this->activityLog->entries())));

        return Command::SUCCESS;
    }
}
