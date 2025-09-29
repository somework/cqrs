<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Registry;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\MessageNamingStrategy;
use SomeWork\CqrsBundle\Registry\HandlerDescriptor;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

final class HandlerRegistryTest extends TestCase
{
    public function test_all_returns_descriptors_for_each_entry(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                [
                    'type' => 'command',
                    'message' => 'App\\Command\\ShipOrder',
                    'handler_class' => 'App\\Command\\ShipOrderHandler',
                    'service_id' => 'app.command.ship_order_handler',
                    'bus' => 'messenger.bus.commands',
                ],
            ],
            'query' => [
                [
                    'type' => 'query',
                    'message' => 'App\\Query\\FindOrder',
                    'handler_class' => 'App\\Query\\FindOrderHandler',
                    'service_id' => 'app.query.find_order_handler',
                    'bus' => null,
                ],
            ],
        ], [
            'default' => $this->strategy('Default %s'),
            'command' => $this->strategy('Command %s'),
        ]);

        $descriptors = $registry->all();

        self::assertCount(2, $descriptors);
        self::assertContainsOnlyInstancesOf(HandlerDescriptor::class, $descriptors);
        self::assertSame('Command App\\Command\\ShipOrder', $registry->getDisplayName($descriptors[0]));
        self::assertSame('Default App\\Query\\FindOrder', $registry->getDisplayName($descriptors[1]));
    }

    public function test_by_type_filters_descriptors(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                [
                    'type' => 'command',
                    'message' => 'App\\Command\\ShipOrder',
                    'handler_class' => 'App\\Command\\ShipOrderHandler',
                    'service_id' => 'app.command.ship_order_handler',
                    'bus' => null,
                ],
            ],
        ], [
            'default' => $this->strategy('%s'),
        ]);

        $descriptors = $registry->byType('command');

        self::assertCount(1, $descriptors);
        self::assertSame('App\\Command\\ShipOrder', $descriptors[0]->messageClass);
    }

    private function createRegistry(array $metadata, array $strategies): HandlerRegistry
    {
        $factories = [];
        foreach ($strategies as $name => $strategy) {
            $factories[$name] = static fn (): MessageNamingStrategy => $strategy;
        }

        $locator = new ServiceLocator($factories);

        return new HandlerRegistry($metadata, $locator);
    }

    private function strategy(string $format): MessageNamingStrategy
    {
        return new class($format) implements MessageNamingStrategy {
            public function __construct(private readonly string $format)
            {
            }

            public function getName(string $messageClass): string
            {
                return sprintf($this->format, $messageClass);
            }
        };
    }
}
