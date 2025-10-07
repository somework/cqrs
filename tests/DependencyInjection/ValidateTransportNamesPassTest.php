<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ValidateTransportNamesPassTest extends TestCase
{
    public function test_it_throws_when_transport_is_missing(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['missing'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Messenger transport "missing" configured for SomeWork CQRS is not defined.');

        $container->compile();
    }

    public function test_it_allows_known_transports(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $container->register('messenger.transport.known', \stdClass::class);

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['known'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $container->compile();

        self::assertTrue(true);
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $bundle = new SomeWorkCqrsBundle();
        $bundle->build($container);

        return $container;
    }
}
