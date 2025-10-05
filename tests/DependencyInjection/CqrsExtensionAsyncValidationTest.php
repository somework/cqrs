<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
                            'App\\Domain\\Event\\OrderPlaced' => DispatchMode::ASYNC->value,
                        ],
                    ],
                ],
            ],
        ], $container);
    }
}
