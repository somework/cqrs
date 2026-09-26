<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Relay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\Outbox\TransactionalOutbox;
use SomeWork\CqrsBundle\Outbox\Relay\RelayOnTerminateSubscriber;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingRelayCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

#[CoversClass(RelayOnTerminateSubscriber::class)]
final class RelayOnTerminateSubscriberTest extends TestCase
{
    public function test_relays_after_the_request_command_or_worker_message_that_stored_messages(): void
    {
        $events = RelayOnTerminateSubscriber::getSubscribedEvents();
        self::assertSame([KernelEvents::TERMINATE, ConsoleEvents::TERMINATE, WorkerRunningEvent::class], array_keys($events));
        // Before the profiler saves the profile (-1024); in a worker, after the acknowledgement
        // (WorkerRunningEvent follows it) and before Messenger resets the services (-1024).
        self::assertSame(['relay', -1000], $events[KernelEvents::TERMINATE]);
        self::assertSame(['relay', -512], $events[WorkerRunningEvent::class]);

        $relay = new RecordingRelayCommand();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, 25);

        $subscriber->relay();
        self::assertCount(0, $relay->runs, 'Nothing was stored.');

        $subscriber->stored();
        $subscriber->relay();
        $subscriber->relay();
        self::assertSame(['--limit=25 --wait-for-lock=2 --no-reset'], $relay->runs, 'Once per request that stored messages, like sync:// without resetting the services.');
    }

    public function test_relays_again_what_the_relayed_handlers_stored(): void
    {
        // A handler the relay ran (sync://) stored a message in turn, twice.
        $relay = new RecordingRelayCommand();
        $relay->storesDuringRun = 2;
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay);
        $relay->subscriber = $subscriber;

        $subscriber->stored();
        $subscriber->relay();

        self::assertCount(3, $relay->runs);
    }

    public function test_waits_for_the_transaction_to_be_committed(): void
    {
        $transaction = new class implements TransactionalOutbox {
            public bool $open = true;

            public function isInTransaction(): bool
            {
                return $this->open;
            }
        };
        $relay = new RecordingRelayCommand();
        $logger = new RecordingLogger();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, logger: $logger, transaction: $transaction);

        $subscriber->stored();
        $subscriber->relay();
        self::assertCount(0, $relay->runs, 'The rows are not committed yet, and the relay would write in that transaction.');
        self::assertStringContainsString('a transaction is still open on its connection', $logger->records[0]['message'] ?? '');

        $transaction->open = false;
        $subscriber->relay();
        self::assertCount(1, $relay->runs, 'The stored messages are still due.');
    }

    public function test_skips_an_interrupted_command_and_the_relay_command_itself(): void
    {
        $relay = new RecordingRelayCommand();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay);
        $subscriber->stored();

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(new Command('app:import'), new ArrayInput([]), new NullOutput(), 130, 2));
        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(new RecordingRelayCommand(), new ArrayInput([]), new NullOutput(), 0));
        self::assertCount(0, $relay->runs);

        $subscriber->onConsoleTerminate(new ConsoleTerminateEvent(new Command('app:import'), new ArrayInput([]), new NullOutput(), 0));
        self::assertCount(1, $relay->runs);
    }

    public function test_leaves_the_messages_to_the_relay_that_keeps_the_lock(): void
    {
        // A --watch relay, or the relay of another request, which fetches until no message is due.
        $relay = new RecordingRelayCommand();
        $relay->exitCode = OutboxRelayCommand::LOCK_TAKEN;
        $logger = new RecordingLogger();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, logger: $logger);

        $subscriber->stored();
        $subscriber->relay();
        self::assertTrue($logger->hasRecordContaining('info', 'relayed by another relay'));

        // A worker does not wait for the lock again after every later message.
        $subscriber->relay();
        self::assertCount(1, $relay->runs);
    }

    public function test_relays_again_while_the_runs_stop_at_their_limit(): void
    {
        // A command stored 250 messages: runs of 100 until one is not full.
        $relay = new RecordingRelayCommand();
        $relay->fullRuns = 2;
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, 100);

        $subscriber->stored();
        $subscriber->relay();

        self::assertCount(3, $relay->runs);
    }

    public function test_tells_when_messages_are_left_after_the_last_pass(): void
    {
        $relay = new RecordingRelayCommand();
        $relay->fullRuns = 50;
        $logger = new RecordingLogger();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, 100, $logger);

        $subscriber->stored();
        $subscriber->relay();

        self::assertCount(10, $relay->runs);
        self::assertTrue($logger->hasRecordContaining('notice', 'The outbox still has messages to relay after {count} runs'));
    }

    public function test_a_failing_relay_is_logged_without_breaking_the_request(): void
    {
        $logger = new RecordingLogger();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => throw new \RuntimeException('database is down'), logger: $logger);

        $subscriber->stored();
        $subscriber->relay();

        self::assertStringContainsString('Relaying the outbox after the stored messages failed', $logger->records[0]['message'] ?? '');
    }
}
