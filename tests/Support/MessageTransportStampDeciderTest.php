<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class MessageTransportStampDeciderTest extends TestCase
{
    public function test_appends_transport_names_for_sync_command(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverForMessage(CreateTaskCommand::class, ['sync']);
        $decider = $this->createDecider(
            command: $commandResolver,
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        $this->assertStampTransports(['sync'], $stamps);
    }

    public function test_appends_transport_names_for_async_command(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverThatShouldNotBeCalled();
        $commandAsyncResolver = $this->resolverForMessage(CreateTaskCommand::class, ['async']);
        $decider = $this->createDecider(
            command: $commandResolver,
            commandAsync: $commandAsyncResolver,
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        $this->assertStampTransports(['async'], $stamps);
    }

    public function test_appends_transport_names_for_query(): void
    {
        $message = new FindTaskQuery('123');

        $queryResolver = $this->resolverForMessage(FindTaskQuery::class, ['queries']);
        $decider = $this->createDecider(
            command: $this->resolverThatShouldNotBeCalled(),
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $queryResolver,
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        $this->assertStampTransports(['queries'], $stamps);
    }

    public function test_appends_transport_names_for_events(): void
    {
        $message = new TaskCreatedEvent('123');

        $eventResolver = $this->resolverForMessage(TaskCreatedEvent::class, ['events']);
        $decider = $this->createDecider(
            command: $this->resolverThatShouldNotBeCalled(),
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $eventResolver,
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        $this->assertStampTransports(['events'], $stamps);
    }

    public function test_appends_transport_names_for_async_events(): void
    {
        $message = new TaskCreatedEvent('123');

        $eventAsyncResolver = $this->resolverForMessage(TaskCreatedEvent::class, ['async_events']);
        $decider = $this->createDecider(
            command: $this->resolverThatShouldNotBeCalled(),
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $eventAsyncResolver,
        );

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        $this->assertStampTransports(['async_events'], $stamps);
    }

    public function test_ignores_when_resolver_returns_null(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = new MessageTransportResolver(new ServiceLocator([]));
        $decider = $this->createDecider(
            command: $commandResolver,
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_ignores_when_resolver_returns_empty_list(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverForMessage(CreateTaskCommand::class, []);
        $decider = $this->createDecider(
            command: $commandResolver,
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_returns_stamps_unchanged_for_non_message_object(): void
    {
        $nonMessage = new stdClass();
        $existingStamp = $this->createMock(StampInterface::class);

        $decider = $this->createDecider(
            command: $this->resolverThatShouldNotBeCalled(),
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($nonMessage, DispatchMode::SYNC, [$existingStamp]);

        self::assertSame([$existingStamp], $stamps);
    }

    public function test_returns_stamps_unchanged_when_resolver_is_null(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $decider = $this->createDecider(
            command: null,
            commandAsync: null,
            query: null,
            event: null,
            eventAsync: null,
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_message_types_returns_marker_interfaces(): void
    {
        $decider = $this->createDecider();

        $types = $decider->messageTypes();

        self::assertSame([Command::class, Query::class, Event::class], $types);
    }

    public function test_does_not_override_existing_transport_stamps(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $existing = new TransportNamesStamp(['existing']);

        $decider = $this->createDecider(
            command: $this->resolverThatShouldNotBeCalled(),
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    private function createDecider(
        ?MessageTransportResolver $command = null,
        ?MessageTransportResolver $commandAsync = null,
        ?MessageTransportResolver $query = null,
        ?MessageTransportResolver $event = null,
        ?MessageTransportResolver $eventAsync = null,
        ?MessageTransportStampFactory $factory = null,
    ): MessageTransportStampDecider {
        return new MessageTransportStampDecider(
            stampFactory: $factory ?? new MessageTransportStampFactory(),
            commandResolvers: new TransportResolverMap(sync: $command, async: $commandAsync),
            queryResolvers: new TransportResolverMap(sync: $query),
            eventResolvers: new TransportResolverMap(sync: $event, async: $eventAsync),
        );
    }

    /**
     * @param list<string>               $expected
     * @param array<int, StampInterface> $stamps
     */
    private function assertStampTransports(array $expected, array $stamps): void
    {
        $filtered = array_filter($stamps, static fn ($s) => $s instanceof TransportNamesStamp);
        self::assertCount(1, $filtered);
        $stamp = reset($filtered);
        self::assertSame($expected, $stamp->getTransportNames());
    }

    /**
     * @param list<string> $transports
     */
    private function resolverForMessage(string $messageClass, array $transports): MessageTransportResolver
    {
        return new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static function (): array {
                throw new \RuntimeException('Default transports should not be used in tests.');
            },
            $messageClass => static fn (): array => $transports,
        ]));
    }

    private function resolverThatShouldNotBeCalled(): MessageTransportResolver
    {
        return new MessageTransportResolver(new ServiceLocator([
            MessageTransportResolver::DEFAULT_KEY => static function (): array {
                throw new \RuntimeException('This resolver should not be used.');
            },
        ]));
    }
}
