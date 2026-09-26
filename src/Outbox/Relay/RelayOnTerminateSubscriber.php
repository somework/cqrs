<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * "outbox.relay_on_terminate" (for development): when a request, a console command or a message a
 * worker handled stored messages in the outbox, runs the relay right after it, once its
 * transaction is committed. The messages then take the same path as in production, through the
 * outbox table, without a relay running on a schedule; with a sync:// transport they are handled
 * right away.
 *
 * Messages the relayed handlers store are relayed in the next pass, up to MAX_PASSES. When
 * another relay holds the lock (outbox:relay --watch), it relays them instead.
 *
 * @internal
 */
final class RelayOnTerminateSubscriber implements EventSubscriberInterface
{
    private const MAX_PASSES = 10;

    private bool $stored = false;

    private bool $relaying = false;

    /**
     * @param \Closure(): Command $relayCommand The somework:cqrs:outbox:relay command, loaded when needed
     */
    public function __construct(
        private readonly \Closure $relayCommand,
        private readonly int $limit = 100,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Late: after the application's own listeners (e.g. a transaction committed on terminate).
        return [
            KernelEvents::TERMINATE => ['relay', -1024],
            ConsoleEvents::TERMINATE => ['relay', -1024],
            WorkerMessageHandledEvent::class => ['relay', -1024],
            WorkerMessageFailedEvent::class => ['relay', -1024],
        ];
    }

    /**
     * Called by OutboxWriter for every message it stores.
     */
    public function stored(): void
    {
        $this->stored = true;
    }

    public function relay(): void
    {
        if (!$this->stored || $this->relaying) {
            return;
        }

        $this->relaying = true;
        try {
            for ($pass = 0; $this->stored && $pass < self::MAX_PASSES; ++$pass) {
                $this->stored = false;
                $input = new ArrayInput(['--limit' => (string) $this->limit]);
                $input->setInteractive(false);
                ($this->relayCommand)()->run($input, new NullOutput());
            }
        } catch (\Throwable $exception) {
            // The rows stay in the outbox for the next run; the request or command already finished.
            $this->logger?->error('Relaying the outbox after the stored messages failed: {error}', ['error' => $exception->getMessage(), 'exception' => $exception]);
        } finally {
            $this->relaying = false;
        }
    }
}
