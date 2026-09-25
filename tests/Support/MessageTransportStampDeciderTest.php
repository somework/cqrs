<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AttributeRoutedCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[CoversClass(MessageTransportStampDecider::class)]
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

    public function test_warns_when_an_async_dispatch_has_no_transport(): void
    {
        // Messenger would handle it in the calling process; also for a dispatch deferred until a handler finished.
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });
        $decider = new MessageTransportStampDecider(new TransportResolverMap(), new TransportResolverMap(), new TransportResolverMap(), [], $logger);

        self::assertSame([], $decider->decide(new TaskCreatedEvent('1'), DispatchMode::ASYNC, []));
        self::assertSame([], $decider->decide(new CreateTaskCommand('1', 'a'), DispatchMode::SYNC, []), 'A sync dispatch needs no transport.');

        self::assertCount(1, $warnings);
        self::assertStringContainsString('is dispatched asynchronously, but no transport is configured for it', $warnings[0][0]);
        self::assertSame(['message' => TaskCreatedEvent::class, 'type' => 'event'], $warnings[0][1]);
    }

    public function test_does_not_warn_when_the_routing_sends_an_async_dispatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $decider = new MessageTransportStampDecider(new TransportResolverMap(), new TransportResolverMap(), new TransportResolverMap(), ['SomeWork\\CqrsBundle\\Tests\\Fixture\\Message\\*'], $logger);

        self::assertSame([], $decider->decide(new CreateTaskCommand('1', 'a'), DispatchMode::ASYNC, []));
    }

    #[RequiresMethod(AsMessage::class, '__construct')]
    public function test_a_message_routed_by_as_message_keeps_its_routing(): void
    {
        // A bare #[Asynchronous] defers to Messenger's routing, #[AsMessage(transport: ...)] included.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $decider = new MessageTransportStampDecider(new TransportResolverMap(), new TransportResolverMap(), new TransportResolverMap(), [], $logger);

        self::assertSame([], $decider->decide(new AttributeRoutedCommand(), DispatchMode::ASYNC, []));
    }

    public function test_transports_for_never_warns(): void
    {
        // Used by OutboxWriter, which stores the message for the relay instead of dispatching it.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $decider = new MessageTransportStampDecider(new TransportResolverMap(), new TransportResolverMap(), new TransportResolverMap(), [], $logger);

        self::assertNull($decider->transportsFor(new CreateTaskCommand('1', 'a'), DispatchMode::ASYNC));
    }

    private function createDecider(
        ?MessageTransportResolver $command = null,
        ?MessageTransportResolver $commandAsync = null,
        ?MessageTransportResolver $query = null,
        ?MessageTransportResolver $event = null,
        ?MessageTransportResolver $eventAsync = null,
    ): MessageTransportStampDecider {
        return new MessageTransportStampDecider(
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
