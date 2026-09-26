<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Outbox\Relay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Outbox\Relay\RelayOnTerminateSubscriber;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\RecordingRelayCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[CoversClass(RelayOnTerminateSubscriber::class)]
final class RelayOnTerminateSubscriberTest extends TestCase
{
    public function test_relays_after_the_request_command_or_worker_message_that_stored_messages(): void
    {
        self::assertSame(
            [KernelEvents::TERMINATE, ConsoleEvents::TERMINATE, WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class],
            array_keys(RelayOnTerminateSubscriber::getSubscribedEvents()),
        );

        $relay = new RecordingRelayCommand();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => $relay, 25);

        $subscriber->relay();
        self::assertSame([], $relay->runs, 'Nothing was stored.');

        $subscriber->stored();
        $subscriber->relay();
        $subscriber->relay();
        self::assertSame(['25'], $relay->runs, 'Once per request that stored messages.');
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

    public function test_a_failing_relay_is_logged_without_breaking_the_request(): void
    {
        $logger = new RecordingLogger();
        $subscriber = new RelayOnTerminateSubscriber(static fn (): Command => throw new \RuntimeException('database is down'), logger: $logger);

        $subscriber->stored();
        $subscriber->relay();

        self::assertStringContainsString('Relaying the outbox after the stored messages failed', $logger->records[0]['message'] ?? '');
    }
}
