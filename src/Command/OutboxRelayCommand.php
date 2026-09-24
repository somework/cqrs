<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Command;

use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function count;
use function filter_var;
use function json_decode;
use function sprintf;

use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;

/**
 * Relays unpublished outbox messages to their transports (at-least-once delivery).
 *
 * @internal
 */
#[AsCommand(
    name: 'somework:cqrs:outbox:relay',
    description: 'Relay unpublished outbox messages to their transports.',
)]
final class OutboxRelayCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly OutboxStorage $outboxStorage,
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $messageBus,
        ?LockFactory $lockFactory = null,
    ) {
        parent::__construct();

        $this->lockFactory = $lockFactory;
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to relay in this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1) {
            $io->error('Limit must be a positive integer.');

            return self::INVALID;
        }

        // Overlapping runs (cron) would publish the same rows twice.
        if (class_exists(LockFactory::class) && !$this->lock()) {
            $io->note('Another outbox relay is already running.');

            return self::SUCCESS;
        }

        try {
            return $this->relay($io, $limit);
        } finally {
            $this->release();
        }
    }

    private function relay(SymfonyStyle $io, int $limit): int
    {
        $relayed = 0;
        $failed = 0;

        while ($relayed < $limit) {
            $requested = $limit - $relayed;
            // Messages that failed in this run stay unpublished at the head of the queue: skip them.
            $batch = $this->outboxStorage->fetchUnpublished($requested, $failed);

            foreach ($batch as $message) {
                try {
                    $this->relayMessage($message, $io);
                    ++$relayed;
                } catch (\Throwable $exception) {
                    ++$failed;
                    $io->error(sprintf('Failed to relay message "%s": %s', $message->id, $exception->getMessage()));
                }
            }

            if (count($batch) < $requested) {
                break;
            }
        }

        if (0 === $relayed && 0 === $failed) {
            $io->info('No unpublished messages found.');

            return self::SUCCESS;
        }

        if ($relayed > 0) {
            $io->success(sprintf('Relayed %d message(s).', $relayed));
        }

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    private function relayMessage(OutboxMessage $message, SymfonyStyle $io): void
    {
        $envelope = $this->serializer->decode([
            'body' => $message->body,
            'headers' => json_decode($message->headers, true, 512, JSON_THROW_ON_ERROR),
        ]);

        // Since Symfony 8, serializers report decoding failures inside the envelope instead of throwing.
        $decoded = $envelope->getMessage();
        if ($decoded instanceof MessageDecodingFailedException) {
            throw $decoded;
        }
        if (null !== $envelope->last(MessageDecodingFailedStamp::class)) {
            throw new MessageDecodingFailedException(sprintf('The class of the message (%s) cannot be loaded.', $decoded::class));
        }

        if (null !== $message->transportName) {
            $envelope = $envelope->with(new TransportNamesStamp([$message->transportName]));
        }

        $envelope = $this->messageBus->dispatch($envelope);

        if (null === $envelope->last(SentStamp::class)) {
            $io->warning(sprintf('Message "%s" (%s) was not sent to any transport and was handled synchronously. Set a transport name or route the message to a transport.', $message->id, $envelope->getMessage()::class));
        }

        $this->outboxStorage->markPublished($message->id);
    }
}
