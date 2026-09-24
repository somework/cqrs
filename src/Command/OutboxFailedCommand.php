<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function array_values;
use function filter_var;
use function is_array;
use function sprintf;

use const DATE_ATOM;
use const FILTER_VALIDATE_INT;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:failed',
    description: 'List the outbox messages the relay gave up on, or hand them back to the relay.',
)]
final class OutboxFailedCommand extends Command
{
    public function __construct(private readonly OutboxStorage $outboxStorage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ids', InputArgument::IS_ARRAY, 'Ids of the messages to requeue (with --requeue); all given-up messages when omitted')
            ->addOption('requeue', null, InputOption::VALUE_NONE, 'Requeue the messages with a fresh attempt counter instead of listing them')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to list', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->outboxStorage instanceof DbalOutboxStorage) {
            $io->error(sprintf('The outbox storage (%s) is not the DBAL storage; inspect its failed messages yourself.', $this->outboxStorage::class));

            return self::FAILURE;
        }

        $ids = $input->getArgument('ids');
        $ids = is_array($ids) ? array_values(array_map('strval', $ids)) : [];

        if (true === $input->getOption('requeue')) {
            $requeued = $this->outboxStorage->requeueFailed($ids);
            $io->success(sprintf('Requeued %d message(s); the next relay run sends them.', $requeued));

            return self::SUCCESS;
        }

        if ([] !== $ids) {
            $io->error('Message ids are only accepted together with --requeue.');

            return self::INVALID;
        }

        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (false === $limit || $limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::INVALID;
        }

        $failed = $this->outboxStorage->fetchFailed($limit);
        if ([] === $failed) {
            $io->success('The relay has not given up on any message.');

            return self::SUCCESS;
        }

        $io->table(
            ['Id', 'Transport', 'Created', 'Given up', 'Attempts', 'Last error'],
            array_map(static fn (array $message): array => [
                $message['id'],
                $message['transport_name'] ?? '(routing)',
                $message['created_at']->format(DATE_ATOM),
                $message['failed_at']->format(DATE_ATOM),
                $message['attempts'],
                $message['last_error'] ?? '',
            ], $failed),
        );
        $io->note('Fix the cause, then run this command with --requeue (optionally followed by message ids).');

        return self::SUCCESS;
    }
}
