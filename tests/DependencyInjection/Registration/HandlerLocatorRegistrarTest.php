<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Registration\HandlerLocatorRegistrar;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function md5;
use function sprintf;

#[CoversClass(HandlerLocatorRegistrar::class)]
final class HandlerLocatorRegistrarTest extends TestCase
{
    public function test_throws_on_circular_alias(): void
    {
        $container = new ContainerBuilder();
        $container->register('bus.real', \stdClass::class);

        $container->setAlias('bus.a', 'bus.b');
        $container->setAlias('bus.b', 'bus.a');

        $registrar = new HandlerLocatorRegistrar();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');

        $registrar->register($container, ['command' => 'bus.a'], 'bus.real');
    }

    public function test_resolves_non_circular_alias_chain(): void
    {
        $container = new ContainerBuilder();
        $container->register('bus.real', \stdClass::class);

        $locatorId = sprintf('%s.messenger.handlers_locator', 'bus.real');
        $container->register($locatorId, \stdClass::class);

        $container->setAlias('bus.alias', 'bus.real');

        $registrar = new HandlerLocatorRegistrar();
        $registrar->register($container, ['command' => 'bus.alias'], 'bus.real');

        $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));
        self::assertTrue($container->hasDefinition($decoratorId));
    }

    public function test_resolves_direct_service_id(): void
    {
        $container = new ContainerBuilder();
        $container->register('messenger.bus.default', \stdClass::class);

        $locatorId = 'messenger.bus.default.messenger.handlers_locator';
        $container->register($locatorId, \stdClass::class);

        $registrar = new HandlerLocatorRegistrar();
        $registrar->register($container, ['command' => 'messenger.bus.default'], 'messenger.bus.default');

        $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));
        self::assertTrue($container->hasDefinition($decoratorId));
    }

    public function test_throws_on_longer_circular_alias_chain(): void
    {
        $container = new ContainerBuilder();
        $container->register('bus.real', \stdClass::class);

        $container->setAlias('bus.a', 'bus.b');
        $container->setAlias('bus.b', 'bus.c');
        $container->setAlias('bus.c', 'bus.a');

        $registrar = new HandlerLocatorRegistrar();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');

        $registrar->register($container, ['command' => 'bus.a'], 'bus.real');
    }

    public function test_deduplicates_bus_ids(): void
    {
        $container = new ContainerBuilder();
        $container->register('messenger.bus.default', \stdClass::class);

        $locatorId = 'messenger.bus.default.messenger.handlers_locator';
        $container->register($locatorId, \stdClass::class);

        $registrar = new HandlerLocatorRegistrar();
        $registrar->register(
            $container,
            ['command' => 'messenger.bus.default', 'query' => 'messenger.bus.default'],
            'messenger.bus.default',
        );

        $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));
        self::assertTrue($container->hasDefinition($decoratorId));

        $allDefinitions = $container->getDefinitions();
        $decoratorCount = 0;
        foreach ($allDefinitions as $id => $definition) {
            if (str_starts_with($id, 'somework_cqrs.envelope_aware_handlers_locator.')) {
                ++$decoratorCount;
            }
        }

        self::assertSame(1, $decoratorCount, 'Duplicate bus IDs should produce only one decorator');
    }
}
