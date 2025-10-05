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

    private function createCommand(HandlerRegistry $registry): ListHandlersCommand
    {
        $dispatchModeDecider = new DispatchModeDecider(DispatchMode::SYNC, DispatchMode::SYNC);
        $dispatchAfter = DispatchAfterCurrentBusDecider::defaults();

        $retryResolver = RetryPolicyResolver::withoutOverrides(new NullRetryPolicy());
        $serializerResolver = MessageSerializerResolver::withoutOverrides(new NullMessageSerializer());
        $metadataResolver = MessageMetadataProviderResolver::withoutOverrides(new RandomCorrelationMetadataProvider());

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
        );
    }
}
