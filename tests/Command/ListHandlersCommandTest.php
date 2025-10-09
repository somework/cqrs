<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\DispatchModeDecider;
use SomeWork\CqrsBundle\Command\ListHandlersCommand;
use SomeWork\CqrsBundle\Contract\MessageNamingStrategy;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use SomeWork\CqrsBundle\Support\DispatchAfterCurrentBusDecider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ListHandlersCommandTest extends TestCase
{
    public function test_lists_all_handlers_sorted_by_type_and_name(): void
    {
        $registry = $this->createRegistry([
            'command' => [[
                'type' => 'command',
                'message' => 'App\\Application\\Command\\ShipOrder',
                'handler_class' => 'App\\Application\\Command\\ShipOrderHandler',
                'service_id' => 'app.command.ship_order_handler',
                'bus' => 'messenger.bus.commands',
            ]],
            'query' => [[
                'type' => 'query',
                'message' => 'App\\ReadModel\\Query\\FindOrder',
                'handler_class' => 'App\\ReadModel\\Query\\FindOrderHandler',
                'service_id' => 'app.read_model.find_order_handler',
                'bus' => null,
            ]],
            'event' => [],
        ], [
            'default' => 'Find order',
            'command' => 'Ship order',
        ]);

        $tester = new CommandTester($this->createCommand($registry));

        $exitCode = $tester->execute([]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Command', $output);
        self::assertStringContainsString('Query', $output);
        self::assertStringContainsString('Ship order', $output);
        self::assertStringContainsString('Find order', $output);

        $commandIndex = strpos($output, 'Ship order');
        $queryIndex = strpos($output, 'Find order');
        self::assertIsInt($commandIndex);
        self::assertIsInt($queryIndex);
        self::assertLessThan($queryIndex, $commandIndex);
    }

    public function test_filters_by_type_option(): void
    {
        $registry = $this->createRegistry([
            'command' => [[
                'type' => 'command',
                'message' => 'App\\Application\\Command\\ShipOrder',
                'handler_class' => 'App\\Application\\Command\\ShipOrderHandler',
                'service_id' => 'app.command.ship_order_handler',
                'bus' => null,
            ]],
            'query' => [[
                'type' => 'query',
                'message' => 'App\\ReadModel\\Query\\FindOrder',
                'handler_class' => 'App\\ReadModel\\Query\\FindOrderHandler',
                'service_id' => 'app.read_model.find_order_handler',
                'bus' => null,
            ]],
            'event' => [],
        ], [
            'default' => 'Default',
            'command' => 'Ship order',
            'query' => 'Find order',
        ]);

        $tester = new CommandTester($this->createCommand($registry));

        $exitCode = $tester->execute(['--type' => ['command']]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Ship order', $output);
        self::assertStringNotContainsString('Find order', $output);
    }

    public function test_warns_when_no_handlers_found(): void
    {
        $registry = $this->createRegistry([
            'command' => [],
            'query' => [],
            'event' => [],
        ], [
            'default' => 'Default',
        ]);

        $tester = new CommandTester($this->createCommand($registry));

        $exitCode = $tester->execute(['--type' => ['unknown']]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertStringContainsString('No CQRS handlers were found', $tester->getDisplay());
    }

    public function test_details_option_displays_configuration_and_handles_uninstantiable_messages(): void
    {
        $registry = $this->createRegistry([
            'command' => [[
                'type' => 'command',
                'message' => TestAsyncCommand::class,
                'handler_class' => TestAsyncCommandHandler::class,
                'service_id' => 'app.command.async_handler',
                'bus' => 'messenger.bus.commands',
            ], [
                'type' => 'command',
                'message' => TestBrokenCommand::class,
                'handler_class' => TestBrokenCommandHandler::class,
                'service_id' => 'app.command.broken_handler',
                'bus' => 'messenger.bus.commands',
            ]],
            'query' => [[
                'type' => 'query',
                'message' => TestQuery::class,
                'handler_class' => TestQueryHandler::class,
                'service_id' => 'app.query.handler',
                'bus' => null,
            ]],
            'event' => [[
                'type' => 'event',
                'message' => TestEvent::class,
                'handler_class' => TestEventHandler::class,
                'service_id' => 'app.event.handler',
                'bus' => 'messenger.bus.events',
            ]],
        ], [
            'default' => 'Default label',
            'command' => 'Command label',
            'query' => 'Query label',
            'event' => 'Event label',
        ]);

        $dispatchModeDecider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::ASYNC);
        $dispatchAfter = new DispatchAfterCurrentBusDecider(true, new ServiceLocator([]), false, new ServiceLocator([]));

        $commandTransports = $this->createTransportResolver([], [
            TestAsyncCommand::class => ['cmd_sync_a', 'cmd_sync_b'],
        ]);
        $commandAsyncTransports = $this->createTransportResolver(['cmd_async_default'], [
            TestAsyncCommand::class => ['cmd_async_override'],
        ]);
        $queryTransports = $this->createTransportResolver(['qry_sync_default']);
        $eventTransports = $this->createTransportResolver([], [
            TestEvent::class => ['evt_sync_specific'],
        ]);
        $eventAsyncTransports = $this->createTransportResolver(['evt_async_default'], [
            TestEvent::class => ['evt_async_specific'],
        ]);

        $tester = new CommandTester($this->createCommand(
            $registry,
            $dispatchModeDecider,
            $dispatchAfter,
            $commandTransports,
            $commandAsyncTransports,
            $queryTransports,
            $eventTransports,
            $eventAsyncTransports,
        ));

        $exitCode = $tester->execute(['--details' => true]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Dispatch Mode', $output);
        self::assertStringContainsString('Async Defers', $output);
        self::assertStringContainsString('Retry Policy', $output);
        self::assertStringContainsString('Serializer', $output);
        self::assertStringContainsString('Metadata Provider', $output);
        self::assertStringContainsString('Sync Transports', $output);
        self::assertStringContainsString('Async Transports', $output);

        $retryClass = preg_quote(NullRetryPolicy::class, '/');
        $serializerClass = preg_quote(NullMessageSerializer::class, '/');
        $metadataClass = preg_quote(RandomCorrelationMetadataProvider::class, '/');

        $commandSyncTransports = preg_quote('cmd_sync_a, cmd_sync_b', '/');
        $commandAsyncTransports = preg_quote('cmd_async_override', '/');
        $querySyncTransports = preg_quote('qry_sync_default', '/');
        $eventSyncTransports = preg_quote('evt_sync_specific', '/');
        $eventAsyncTransports = preg_quote('evt_async_specific', '/');

        $commandRowPattern = '/\|\s*Command\s*\|\s*Command label\s*\|\s*'.preg_quote(TestAsyncCommandHandler::class, '/').'\s*\|\s*app\\.command\\.async_handler\s*\|\s*messenger\\.bus\\.commands\s*\|\s*sync\s*\|\s*yes\s*\|\s*'.$commandSyncTransports.'\s*\|\s*'.$commandAsyncTransports.'\s*\|\s*'.$retryClass.'\s*\|\s*'.$serializerClass.'\s*\|\s*'.$metadataClass.'\s*\|/';
        self::assertMatchesRegularExpression($commandRowPattern, $output);

        $eventRowPattern = '/\|\s*Event\s*\|\s*Event label\s*\|\s*'.preg_quote(TestEventHandler::class, '/').'\s*\|\s*app\\.event\\.handler\s*\|\s*messenger\\.bus\\.events\s*\|\s*async\s*\|\s*no\s*\|\s*'.$eventSyncTransports.'\s*\|\s*'.$eventAsyncTransports.'\s*\|\s*'.$retryClass.'\s*\|\s*'.$serializerClass.'\s*\|\s*'.$metadataClass.'\s*\|/';
        self::assertMatchesRegularExpression($eventRowPattern, $output);

        $queryRowPattern = '/\|\s*Query\s*\|\s*Query label\s*\|\s*'.preg_quote(TestQueryHandler::class, '/').'\s*\|\s*app\\.query\\.handler\s*\|\s*default\s*\|\s*sync\s*\|\s*n\/a\s*\|\s*'.$querySyncTransports.'\s*\|\s*n\/a\s*\|\s*'.$retryClass.'\s*\|\s*'.$serializerClass.'\s*\|\s*'.$metadataClass.'\s*\|/';
        self::assertMatchesRegularExpression($queryRowPattern, $output);

        $brokenRowPattern = '/\|\s*Command\s*\|\s*Command label\s*\|\s*'.preg_quote(TestBrokenCommandHandler::class, '/').'\s*\|\s*app\\.command\\.broken_handler\s*\|\s*messenger\\.bus\\.commands\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*n\/a\s*\|\s*n\/a\s*\|/';
        self::assertMatchesRegularExpression($brokenRowPattern, $output);
    }

    public function test_details_option_handles_missing_async_transport_resolvers(): void
    {
        $registry = $this->createRegistry([
            'command' => [[
                'type' => 'command',
                'message' => TestAsyncCommand::class,
                'handler_class' => TestAsyncCommandHandler::class,
                'service_id' => 'app.command.async_handler',
                'bus' => 'messenger.bus.commands',
            ]],
            'query' => [],
            'event' => [],
        ], [
            'default' => 'Default label',
            'command' => 'Command label',
        ]);

        $commandTransports = $this->createTransportResolver(['cmd_sync_only']);

        $tester = new CommandTester($this->createCommand(
            $registry,
            null,
            null,
            $commandTransports,
            null,
        ));

        $tester->execute(['--details' => true]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('cmd_sync_only', $output);

        $asyncColumnPattern = '/\|\s*Command\s*\|\s*Command label\s*\|\s*'.preg_quote(TestAsyncCommandHandler::class, '/').'\s*\|\s*app\\.command\\.async_handler\s*\|\s*messenger\\.bus\\.commands\s*\|\s*sync\s*\|\s*yes\s*\|\s*cmd_sync_only\s*\|\s*n\/a\s*\|/';
        self::assertMatchesRegularExpression($asyncColumnPattern, $output);
    }

    /**
     * @param array<string, list<array{type: string, message: class-string, handler_class: class-string, service_id: string, bus: string|null}>> $metadata
     * @param array<string, string>                                                                                                              $labels
     */
    private function createRegistry(array $metadata, array $labels): HandlerRegistry
    {
        $strategies = [];
        foreach ($labels as $type => $label) {
            $strategies[$type] = $this->labelStrategy($label);
        }

        if (!isset($strategies['default'])) {
            $strategies['default'] = $this->labelStrategy('default');
        }

        $factories = [];
        foreach ($strategies as $name => $strategy) {
            $factories[$name] = static fn (): MessageNamingStrategy => $strategy;
        }

        return new HandlerRegistry($metadata, new ServiceLocator($factories));
    }

    private function labelStrategy(string $label): MessageNamingStrategy
    {
        return new class($label) implements MessageNamingStrategy {
            public function __construct(private readonly string $label)
            {
            }

            public function getName(string $messageClass): string
            {
                return $this->label;
            }
        };
    }

    private function createCommand(
        HandlerRegistry $registry,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $commandTransports = null,
        ?MessageTransportResolver $commandAsyncTransports = null,
        ?MessageTransportResolver $queryTransports = null,
        ?MessageTransportResolver $eventTransports = null,
        ?MessageTransportResolver $eventAsyncTransports = null,
    ): ListHandlersCommand {
        $dispatchModeDecider ??= new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC);
        $dispatchAfter ??= DispatchAfterCurrentBusDecider::defaults();

        $retryResolver = RetryPolicyResolver::withoutOverrides(new NullRetryPolicy());
        $serializerResolver = MessageSerializerResolver::withoutOverrides(new NullMessageSerializer());
        $metadataResolver = MessageMetadataProviderResolver::withoutOverrides(new RandomCorrelationMetadataProvider());

        $commandTransports ??= $this->createTransportResolver();
        $queryTransports ??= $this->createTransportResolver();
        $eventTransports ??= $this->createTransportResolver();

        return new ListHandlersCommand(
            $registry,
            $dispatchModeDecider,
            $dispatchAfter,
            RetryPolicyResolver::withoutOverrides(new NullRetryPolicy()),
            RetryPolicyResolver::withoutOverrides(new NullRetryPolicy()),
            $retryResolver,
            MessageSerializerResolver::withoutOverrides(new NullMessageSerializer()),
            MessageSerializerResolver::withoutOverrides(new NullMessageSerializer()),
            $serializerResolver,
            MessageMetadataProviderResolver::withoutOverrides(new RandomCorrelationMetadataProvider()),
            MessageMetadataProviderResolver::withoutOverrides(new RandomCorrelationMetadataProvider()),
            $metadataResolver,
            $commandTransports,
            $queryTransports,
            $eventTransports,
            $commandAsyncTransports,
            $eventAsyncTransports,
        );
    }

    private function createTransportResolver(array $default = [], array $map = []): MessageTransportResolver
    {
        $services = [];

        if ([] !== $default) {
            $services[MessageTransportResolver::DEFAULT_KEY] = static fn (): array => $default;
        }

        foreach ($map as $message => $transports) {
            $services[$message] = static fn (): array => $transports;
        }

        return new MessageTransportResolver(new ServiceLocator($services));
    }
}

interface TestBrokenCommand extends \SomeWork\CqrsBundle\Contract\Command
{
}

final class TestAsyncCommand implements \SomeWork\CqrsBundle\Contract\Command
{
}

final class TestAsyncCommandHandler
{
}

final class TestBrokenCommandHandler
{
}

final class TestQuery implements \SomeWork\CqrsBundle\Contract\Query
{
}

final class TestQueryHandler
{
}

final class TestEvent implements \SomeWork\CqrsBundle\Contract\Event
{
}

final class TestEventHandler
{
}
