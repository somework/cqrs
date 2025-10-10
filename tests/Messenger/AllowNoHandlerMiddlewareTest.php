<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Messenger\Middleware\AllowNoHandlerMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class AllowNoHandlerMiddlewareTest extends TestCase
{
    public function test_it_ignores_missing_handlers_for_events(): void
    {
        $bus = new MessageBus([
            new AllowNoHandlerMiddleware(),
            new HandleMessageMiddleware(new HandlersLocator([])),
        ]);

        $envelope = $bus->dispatch(new TaskCreatedEvent('noop'));

        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame('noop', $envelope->getMessage()->taskId);
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
}
