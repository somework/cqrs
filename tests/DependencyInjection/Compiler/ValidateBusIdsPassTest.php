<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateBusIdsPass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBus;

#[CoversClass(ValidateBusIdsPass::class)]
final class ValidateBusIdsPassTest extends TestCase
{
    public function test_accepts_buses_and_aliases_of_buses(): void
    {
        $container = $this->container(['command' => 'command.bus', 'event_async' => 'events']);
        $container->setAlias('events', 'event.async_bus');

        (new ValidateBusIdsPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_an_unknown_bus_id(): void
    {
        $container = $this->container(['command' => 'command.bus', 'event_async' => 'event.asyn_bus']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"somework_cqrs.buses.event_async" is "event.asyn_bus", which is not a Messenger bus. Known buses: command.bus, event.async_bus.');

        (new ValidateBusIdsPass())->process($container);
    }

    public function test_rejects_a_service_that_is_not_a_bus(): void
    {
        $container = $this->container(['query' => 'app.query_service']);
        $container->register('app.query_service', \stdClass::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"somework_cqrs.buses.query" is "app.query_service", which is not a Messenger bus.');

        (new ValidateBusIdsPass())->process($container);
    }

    public function test_does_nothing_without_the_bundle_configuration(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.bus.command', 'missing');

        (new ValidateBusIdsPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string, string> $buses
     */
    private function container(array $buses): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'command.bus');
        foreach ($buses as $key => $busId) {
            $container->setParameter('somework_cqrs.bus.'.$key, $busId);
        }
        $container->register('command.bus', MessageBus::class)->addTag('messenger.bus');
        $container->register('event.async_bus', MessageBus::class)->addTag('messenger.bus');

        return $container;
    }
}
