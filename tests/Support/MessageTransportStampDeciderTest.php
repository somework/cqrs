<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function sprintf;

final class MessageTransportStampDeciderTest extends TestCase
{
    public function test_appends_transport_names_for_sync_command(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverForMessage(CreateTaskCommand::class, ['sync']);
        $decider = new MessageTransportStampDecider(
            $commandResolver,
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['sync'], $stamps[0]->getTransportNames());
    }

    public function test_appends_transport_names_for_async_command(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverThatShouldNotBeCalled();
        $commandAsyncResolver = $this->resolverForMessage(CreateTaskCommand::class, ['async']);
        $decider = new MessageTransportStampDecider(
            $commandResolver,
            $commandAsyncResolver,
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['async'], $stamps[0]->getTransportNames());
    }

    public function test_appends_transport_names_for_query(): void
    {
        $message = new FindTaskQuery('123');

        $queryResolver = $this->resolverForMessage(FindTaskQuery::class, ['queries']);
        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $queryResolver,
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['queries'], $stamps[0]->getTransportNames());
    }

    public function test_appends_transport_names_for_events(): void
    {
        $message = new TaskCreatedEvent('123');

        $eventResolver = $this->resolverForMessage(TaskCreatedEvent::class, ['events']);
        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $eventResolver,
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['events'], $stamps[0]->getTransportNames());
    }

    public function test_appends_transport_names_for_async_events(): void
    {
        $message = new TaskCreatedEvent('123');

        $eventAsyncResolver = $this->resolverForMessage(TaskCreatedEvent::class, ['async_events']);
        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $eventAsyncResolver,
        );

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['async_events'], $stamps[0]->getTransportNames());
    }

    public function test_ignores_when_resolver_returns_null(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = new MessageTransportResolver(new ServiceLocator([]));
        $decider = new MessageTransportStampDecider(
            $commandResolver,
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_ignores_when_resolver_returns_empty_list(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverForMessage(CreateTaskCommand::class, []);
        $decider = new MessageTransportStampDecider(
            $commandResolver,
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_does_not_override_existing_transport_stamps(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $existing = new TransportNamesStamp(['existing']);

        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    public function test_does_not_override_senders_locator_stamp(): void
    {
        $class = 'Symfony\\Component\\Messenger\\Stamp\\SendersLocatorStamp';
        if (!class_exists($class)) {
            self::markTestSkipped(sprintf('%s is not available in this Messenger version.', $class));
        }

        $message = new CreateTaskCommand('123', 'Test');
        $existing = new $class([], []);

        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }

    public function test_does_not_override_send_message_to_transports_stamp(): void
    {
        $class = 'Symfony\\Component\\Messenger\\Stamp\\SendMessageToTransportsStamp';
        if (!class_exists($class)) {
            self::markTestSkipped(sprintf('%s is not available in this Messenger version.', $class));
        }

        $message = new CreateTaskCommand('123', 'Test');
        $existing = new $class(['existing']);

        $decider = new MessageTransportStampDecider(
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
            $this->resolverThatShouldNotBeCalled(),
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, [$existing]);

        self::assertSame([$existing], $stamps);
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
