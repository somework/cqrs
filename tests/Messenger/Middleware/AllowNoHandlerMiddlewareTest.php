<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Messenger\Middleware\AllowNoHandlerMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[CoversClass(AllowNoHandlerMiddleware::class)]
final class AllowNoHandlerMiddlewareTest extends TestCase
{
    public function test_it_ignores_missing_handlers_for_events(): void
    {
        $bus = new MessageBus([
            new AllowNoHandlerMiddleware(),
            new HandleMessageMiddleware(new HandlersLocator([])),
        ]);

        $envelope = $bus->dispatch(new TaskCreatedEvent('noop'));

        $message = $envelope->getMessage();
        self::assertInstanceOf(TaskCreatedEvent::class, $message);
        self::assertSame('noop', $message->taskId);
    }

    public function test_it_rethrows_for_non_event_messages(): void
    {
        $bus = new MessageBus([
            new AllowNoHandlerMiddleware(),
            new HandleMessageMiddleware(new HandlersLocator([])),
        ]);

        $this->expectException(NoHandlerForMessageException::class);

        $bus->dispatch(new \stdClass());
    }

    public function test_it_warns_about_a_received_event_without_handler(): void
    {
        // e.g. its handler is pinned to the sync bus, and a worker consumes it on the async bus.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('has no handler on the bus that consumes it'),
            ['message' => TaskCreatedEvent::class, 'transport' => 'async'],
        );
        $bus = new MessageBus([
            new AllowNoHandlerMiddleware($logger),
            new HandleMessageMiddleware(new HandlersLocator([])),
        ]);

        $bus->dispatch(new Envelope(new TaskCreatedEvent('noop'), [new ReceivedStamp('async')]));
    }

    public function test_it_does_not_warn_about_a_dispatched_event_without_handler(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $bus = new MessageBus([
            new AllowNoHandlerMiddleware($logger),
            new HandleMessageMiddleware(new HandlersLocator([])),
        ]);

        $bus->dispatch(new TaskCreatedEvent('noop'));
    }
}
