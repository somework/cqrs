<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function sprintf;

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

    #[RunInSeparateProcess]
    public function test_appends_send_message_stamp_when_configured(): void
    {
        require_once __DIR__.'/../Fixture/Messenger/SendMessageToTransportsStampStub.php';

        $message = new CreateTaskCommand('123', 'Test');

        $commandResolver = $this->resolverForMessage(CreateTaskCommand::class, ['sync']);
        $decider = $this->createDecider(
            command: $commandResolver,
            commandAsync: $this->resolverThatShouldNotBeCalled(),
            query: $this->resolverThatShouldNotBeCalled(),
            event: $this->resolverThatShouldNotBeCalled(),
            eventAsync: $this->resolverThatShouldNotBeCalled(),
            stampTypes: ['command' => MessageTransportStampFactory::TYPE_SEND_MESSAGE],
        );

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        $this->assertStampTransports(['sync'], $stamps, MessageTransportStampFactory::TYPE_SEND_MESSAGE);
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

    public function test_does_not_override_senders_locator_stamp(): void
    {
        $class = 'Symfony\\Component\\Messenger\\Stamp\\SendersLocatorStamp';
        if (!class_exists($class)) {
            self::markTestSkipped(sprintf('%s is not available in this Messenger version.', $class));
        }

        $message = new CreateTaskCommand('123', 'Test');
        $existing = new $class([], []);

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

    #[RunInSeparateProcess]
    public function test_skips_optional_senders_locator_stamp_when_class_is_missing(): void
    {
        $class = 'Symfony\\Component\\Messenger\\Stamp\\SendersLocatorStamp';
        self::assertFalse(class_exists($class, false));

        $autoloaded = false;
        $loader = static function (string $requested) use ($class, &$autoloaded): void {
            if ($requested === $class) {
                $autoloaded = true;
            }
        };

        spl_autoload_register($loader, true, true);

        try {
            $message = new CreateTaskCommand('123', 'Test');
            $resolver = $this->resolverForMessage(CreateTaskCommand::class, ['sync']);
            $decider = $this->createDecider(
                command: $resolver,
                commandAsync: $this->resolverThatShouldNotBeCalled(),
                query: $this->resolverThatShouldNotBeCalled(),
                event: $this->resolverThatShouldNotBeCalled(),
                eventAsync: $this->resolverThatShouldNotBeCalled(),
            );

            $stamps = $decider->decide($message, DispatchMode::SYNC, [new DummyStamp('existing')]);

            self::assertCount(2, $stamps);
            self::assertInstanceOf(DummyStamp::class, $stamps[0]);
            $this->assertStampTransports(['sync'], [$stamps[1]]);
            self::assertFalse($autoloaded, 'Optional stamp class should not be autoloaded.');
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    #[RunInSeparateProcess]
    public function test_skips_optional_send_message_stamp_when_class_is_missing(): void
    {
        $class = MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS;
        self::assertFalse(class_exists($class, false));

        $autoloaded = false;
        $loader = static function (string $requested) use ($class, &$autoloaded): void {
            if ($requested === $class) {
                $autoloaded = true;
            }
        };

        spl_autoload_register($loader, true, true);

        try {
            $message = new CreateTaskCommand('123', 'Test');
            $resolver = $this->resolverForMessage(CreateTaskCommand::class, ['sync']);
            $decider = $this->createDecider(
                command: $resolver,
                commandAsync: $this->resolverThatShouldNotBeCalled(),
                query: $this->resolverThatShouldNotBeCalled(),
                event: $this->resolverThatShouldNotBeCalled(),
                eventAsync: $this->resolverThatShouldNotBeCalled(),
                stampTypes: ['command' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES],
            );

            $stamps = $decider->decide($message, DispatchMode::SYNC, [new DummyStamp('existing')]);

            self::assertCount(2, $stamps);
            self::assertInstanceOf(DummyStamp::class, $stamps[0]);
            $this->assertStampTransports(['sync'], [$stamps[1]]);
            self::assertFalse($autoloaded, 'Optional stamp class should not be autoloaded.');
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * @param array<string, string> $stampTypes
     */
    private function createDecider(
        ?MessageTransportResolver $command = null,
        ?MessageTransportResolver $commandAsync = null,
        ?MessageTransportResolver $query = null,
        ?MessageTransportResolver $event = null,
        ?MessageTransportResolver $eventAsync = null,
        ?MessageTransportStampFactory $factory = null,
        array $stampTypes = [],
    ): MessageTransportStampDecider {
        return new MessageTransportStampDecider(
            $factory ?? new MessageTransportStampFactory(),
            $command,
            $commandAsync,
            $query,
            $event,
            $eventAsync,
            $stampTypes,
        );
    }

    /**
     * @param list<string>         $expected
     * @param list<StampInterface> $stamps
     */
    private function assertStampTransports(array $expected, array $stamps, string $type = MessageTransportStampFactory::TYPE_TRANSPORT_NAMES): void
    {
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];

        if (MessageTransportStampFactory::TYPE_SEND_MESSAGE === $type) {
            $class = MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS;
            self::assertInstanceOf($class, $stamp);
        } else {
            self::assertInstanceOf(TransportNamesStamp::class, $stamp);
        }

        $getter = null;

        if (method_exists($stamp, 'getTransportNames')) {
            $getter = 'getTransportNames';
        } elseif (method_exists($stamp, 'getTransports')) {
            $getter = 'getTransports';
        }

        self::assertNotNull($getter, 'Transport stamp does not expose transport names.');

        /** @var callable(): array $callable */
        $callable = [$stamp, $getter];

        self::assertSame($expected, $callable());
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
