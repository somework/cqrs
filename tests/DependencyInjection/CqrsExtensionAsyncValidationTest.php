<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArchiveTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CqrsExtension::class)]
final class CqrsExtensionAsyncValidationTest extends TestCase
{
    public function test_it_requires_async_command_bus_when_default_dispatch_is_async(): void
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('somework_cqrs.buses.command_async');

        $extension->load([
            [
                'dispatch_modes' => [
                    'command' => [
                        'default' => DispatchMode::ASYNC->value,
                        'map' => [],
                    ],
                    'event' => [
                        'default' => DispatchMode::SYNC->value,
                        'map' => [],
                    ],
                ],
            ],
        ], $container);
    }

    public function test_it_requires_async_event_bus_when_async_map_entries_exist(): void
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('somework_cqrs.buses.event_async');

        $extension->load([
            [
                'dispatch_modes' => [
                    'command' => [
                        'default' => DispatchMode::SYNC->value,
                        'map' => [],
                    ],
                    'event' => [
                        'default' => DispatchMode::SYNC->value,
                        'map' => [
                            TaskCreatedEvent::class => DispatchMode::ASYNC->value,
                        ],
                    ],
                ],
            ],
        ], $container);
    }

    public function test_the_outbox_dispatch_mode_needs_the_outbox_but_no_async_bus(): void
    {
        try {
            (new CqrsExtension())->load([['dispatch_modes' => ['event' => ['default' => DispatchMode::OUTBOX->value]]]], new ContainerBuilder());
            self::fail('Expected the configuration to be refused.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('"somework_cqrs.dispatch_modes.event" stores messages in the outbox ("default: outbox"), but the outbox is disabled.', $exception->getMessage());
        }

        try {
            (new CqrsExtension())->load([['dispatch_modes' => ['command' => ['map' => [ArchiveTaskCommand::class => 'outbox']]]]], new ContainerBuilder());
            self::fail('Expected the configuration to be refused.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString(ArchiveTaskCommand::class, $exception->getMessage());
        }

        $container = new ContainerBuilder();
        (new CqrsExtension())->load([[
            'dispatch_modes' => ['event' => ['default' => 'outbox'], 'command' => ['map' => [ArchiveTaskCommand::class => 'outbox']]],
            'outbox' => ['enabled' => true],
        ]], $container);

        $decider = $container->getDefinition('somework_cqrs.dispatch_mode_decider');
        self::assertSame(DispatchMode::OUTBOX, $decider->getArgument('$eventDefault'));
        self::assertSame([ArchiveTaskCommand::class => DispatchMode::OUTBOX], $decider->getArgument('$commandMap'));
    }

    public function test_async_transports_need_no_async_bus_with_the_outbox(): void
    {
        $transports = ['event_async' => ['default' => ['async']]];

        try {
            (new CqrsExtension())->load([['transports' => $transports]], new ContainerBuilder());
            self::fail('Expected the configuration to be refused.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('async transport defaults: async', $exception->getMessage());
        }

        // The outbox stores its rows for them (an outbox-only application).
        $container = new ContainerBuilder();
        (new CqrsExtension())->load([['transports' => $transports, 'dispatch_modes' => ['event' => ['default' => 'outbox']], 'outbox' => ['enabled' => true]]], $container);

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.writer'));
    }
}
