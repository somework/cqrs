<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\DependencyInjection\Registration\BusInterfaceRegistrar;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(BusInterfaceRegistrar::class)]
final class BusInterfaceWiringTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[Test]
    public function container_resolves_command_bus_interface_to_command_bus(): void
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);

        self::assertInstanceOf(CommandBus::class, $bus);
    }

    #[Test]
    public function container_resolves_query_bus_interface_to_query_bus(): void
    {
        $bus = static::getContainer()->get(QueryBusInterface::class);

        self::assertInstanceOf(QueryBus::class, $bus);
    }

    #[Test]
    public function container_resolves_event_bus_interface_to_event_bus(): void
    {
        $bus = static::getContainer()->get(EventBusInterface::class);

        self::assertInstanceOf(EventBus::class, $bus);
    }

    #[Test]
    public function interface_aliases_are_public(): void
    {
        $container = static::getContainer();

        // If aliases were not public, these calls would throw
        self::assertNotNull($container->get(CommandBusInterface::class));
        self::assertNotNull($container->get(QueryBusInterface::class));
        self::assertNotNull($container->get(EventBusInterface::class));
    }
}
