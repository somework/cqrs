<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * "outbox.relay_on_terminate" (for development): when a request, a console command or a message a
 * worker handled stored messages in the outbox, runs the relay right after it, once its
 * transaction is committed. The messages then take the same path as in production, through the
 * outbox table, without a relay running on a schedule; with a sync:// transport they are handled
 * right away.
 *
 * Messages the relayed handlers store are relayed in the next pass, up to MAX_PASSES. It does not
 * relay while a transaction is still open on the outbox connection (its rows are not committed
 * yet, and the relay would write inside it), after a command a signal interrupted, or after the
 * relay command itself; the messages then wait for the next request, command or relay. When
 * another relay holds the lock, it waits for it up to LOCK_WAIT_SECONDS, then leaves the messages
 * to that relay.
 *
 * @internal
 */
final class RelayOnTerminateSubscriber implements EventSubscriberInterface
{
    private const MAX_PASSES = 10;

    /** Long enough for the relay of a concurrent request; a --watch relay keeps the lock, and relays the messages itself. */
    private const LOCK_WAIT_SECONDS = 2;

    private bool $stored = false;

    private bool $relaying = false;

    /**
     * @param \Closure(): Command      $relayCommand The somework:cqrs:outbox:relay command, loaded when needed
     * @param TransactionalOutbox|null $transaction  Tells whether a transaction is still open on the outbox connection
     */
    public function __construct(
        private readonly \Closure $relayCommand,
        private readonly int $limit = 100,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?TransactionalOutbox $transaction = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the profiler saves the profile of the request, so what the relayed handlers
            // log shows in it; after the application's own listeners.
            KernelEvents::TERMINATE => ['relay', -1000],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', -1024],
            // After the worker acknowledged the message, before it resets its services.
            WorkerRunningEvent::class => ['relay', -512],
        ];
    }

    /**
     * Called by OutboxWriter for every message it stores.
     */
    public function stored(): void
    {
        $this->stored = true;
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        // An interrupted command may have left its work half done; the relay command relays itself.
        if (null !== $event->getInterruptingSignal() || 'somework:cqrs:outbox:relay' === $event->getCommand()?->getName()) {
            return;
        }

        $this->relay();
    }

    public function relay(): void
    {
        if (!$this->stored || $this->relaying) {
            return;
        }

        $this->relaying = true;
        try {
            if (true === $this->transaction?->isInTransaction()) {
                $this->logger?->notice('The outbox is not relayed yet: a transaction is still open on its connection (with "auto_commit: false", always). Run "bin/console somework:cqrs:outbox:relay" or keep "--watch" running.');

                return;
            }

            for ($pass = 0; $this->stored && $pass < self::MAX_PASSES; ++$pass) {
                $this->stored = false;
                $input = new ArrayInput(['--limit' => (string) $this->limit, '--wait-for-lock' => (string) self::LOCK_WAIT_SECONDS, '--no-reset' => true]);
                $input->setInteractive(false);
                if (OutboxRelayCommand::LOCK_TAKEN === ($this->relayCommand)()->run($input, new NullOutput())) {
                    // Another relay is busy: it, or the next request, relays the messages.
                    $this->stored = true;

                    return;
                }
            }
        } catch (\Throwable $exception) {
            // The rows stay in the outbox for the next run; the request or command already finished.
            $this->logger?->error('Relaying the outbox after the stored messages failed: {error}', ['error' => $exception->getMessage(), 'exception' => $exception]);
        } finally {
            $this->relaying = false;
        }
    }
}
