<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\HealthCheckerLocatorPass;
use SomeWork\CqrsBundle\Health\HandlerResolvabilityChecker;
use SomeWork\CqrsBundle\Health\TransportValidityChecker;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;

#[CoversClass(HealthCheckerLocatorPass::class)]
final class HealthCheckerLocatorPassTest extends TestCase
{
    public function test_does_nothing_without_the_checkers(): void
    {
        $container = new ContainerBuilder();

        (new HealthCheckerLocatorPass())->process($container);

        self::assertFalse($container->hasDefinition(HandlerResolvabilityChecker::class));
    }

    public function test_builds_a_locator_of_the_distinct_handler_services(): void
    {
        $container = $this->container();
        $container->register('handler.a', \stdClass::class);
        $container->register('handler.b', \stdClass::class);
        $container->setParameter('somework_cqrs.handler_metadata', [
            'command' => [
                ['service_id' => 'handler.a', 'bus' => 'bus.sync'],
                ['service_id' => 'handler.a', 'bus' => 'bus.async'],
            ],
            'event' => [
                ['service_id' => 'handler.b', 'bus' => 'bus.events'],
                ['service_id' => 'handler.removed', 'bus' => 'bus.events'],
            ],
        ]);

        (new HealthCheckerLocatorPass())->process($container);

        self::assertSame(['handler.a', 'handler.b'], array_keys(self::locatorValues($container, HandlerResolvabilityChecker::class, '$handlers')));
    }

    public function test_builds_a_locator_of_the_transports_keyed_by_name(): void
    {
        $container = $this->container();
        $container->register('messenger.transport.async', \stdClass::class)->addTag('messenger.receiver', ['alias' => 'async']);
        $container->register('messenger.transport.failed', \stdClass::class)->addTag('messenger.receiver', ['alias' => 'failed']);
        $container->register('app.custom_receiver', \stdClass::class)->addTag('messenger.receiver');

        (new HealthCheckerLocatorPass())->process($container);

        $values = self::locatorValues($container, TransportValidityChecker::class, '$transports');
        self::assertSame(['app.custom_receiver', 'async', 'failed'], array_keys($values));
        self::assertSame('messenger.transport.async', (string) $values['async']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(HandlerResolvabilityChecker::class, HandlerResolvabilityChecker::class);
        $container->register(TransportValidityChecker::class, TransportValidityChecker::class);

        return $container;
    }

    /**
     * @return array<string, Reference>
     */
    private static function locatorValues(ContainerBuilder $container, string $checker, string $argument): array
    {
        $locatorReference = $container->getDefinition($checker)->getArgument($argument);
        self::assertInstanceOf(Reference::class, $locatorReference);

        $values = [];
        foreach ($container->getDefinition((string) $locatorReference)->getArgument(0) as $key => $value) {
            self::assertInstanceOf(ServiceClosureArgument::class, $value);
            $reference = $value->getValues()[0];
            self::assertInstanceOf(Reference::class, $reference);
            $values[$key] = $reference;
        }

        return $values;
    }
}
