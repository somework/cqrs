<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:relay',
    description: 'Relay unpublished outbox messages to their transports.',
)]
final class OutboxRelayCommand extends Command
{
    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max messages to relay per run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::FAILURE;
        }

        $messages = $this->outboxStorage->fetchUnpublished($limit);

        if ([] === $messages) {
            $io->info('No unpublished messages found.');

            return self::SUCCESS;
        }

        $hasFailure = false;
        $relayedCount = 0;

        foreach ($messages as $message) {
            try {
                $envelope = $this->serializer->decode([
                    'body' => $message->body,
                    'headers' => json_decode($message->headers, true, 512, JSON_THROW_ON_ERROR),
                ]);

                $this->messageBus->dispatch($envelope);
                $this->outboxStorage->markPublished($message->id);
                ++$relayedCount;
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to relay message "%s": %s', $message->id, $e->getMessage()));
                $hasFailure = true;
            }
        }

        if ($relayedCount > 0) {
            $io->success(sprintf('Relayed %d message(s).', $relayedCount));
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
