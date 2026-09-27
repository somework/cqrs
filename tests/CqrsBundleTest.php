<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CausationIdMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DeduplicationLockReleasePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\EnvelopeAwareHandlersLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\HealthCheckerLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\LoggerChannelPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OpenTelemetryMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoreMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\TransportRoutingPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_map;
use function array_search;
use function sort;

#[CoversClass(SomeWorkCqrsBundle::class)]
final class CqrsBundleTest extends TestCase
{
    /** The passes that use what MessengerPass builds, in the order they must run. */
    private const AFTER_MESSENGER_PASS = [
        EnvelopeAwareHandlersLocatorPass::class,
        HealthCheckerLocatorPass::class,
        // Each inserts right after "dispatch_after_current_bus": the last one ends up first.
        AllowNoHandlerMiddlewarePass::class,
        CausationIdMiddlewarePass::class,
        OpenTelemetryMiddlewarePass::class,
        DeduplicationLockReleasePass::class,
        OutboxStoreMiddlewarePass::class,
        TransportRoutingPass::class,
        ValidateTransportNamesPass::class,
        // After every pass that adds a service with a logger.
        LoggerChannelPass::class,
    ];

    public function test_bundle_provides_extension_instance(): void
    {
        $bundle = new SomeWorkCqrsBundle();

        self::assertInstanceOf(CqrsExtension::class, $bundle->getContainerExtension());
    }

    public function test_extension_alias_uses_vendor_prefix(): void
    {
        $extension = new CqrsExtension();

        self::assertSame('somework_cqrs', $extension->getAlias());
    }

    public function test_the_passes_run_around_messenger_pass_on_every_supported_symfony_version(): void
    {
        $container = new ContainerBuilder();
        // Up to Symfony 8.1, FrameworkBundle (registered before this bundle) adds MessengerPass at priority 0.
        $container->addCompilerPass($messengerPassUpTo81 = self::marker(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        // From Symfony 8.2 on, MessengerBundle adds it at priority -16.
        $container->addCompilerPass($messengerPassFrom82 = self::marker(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -16);
        // Symfony's ResettableServicePass and LoggerPass (-32) must still see the services of the bundle.
        $container->addCompilerPass($symfonyLatePasses = self::marker(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -32);

        (new SomeWorkCqrsBundle())->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $position = static fn (CompilerPassInterface $pass): int => (int) array_search($pass, $passes, true);
        $positions = [];
        foreach ($passes as $index => $pass) {
            $positions[$pass::class] ??= $index;
        }

        // CqrsHandlerPass normalises the handler tags MessengerPass consumes.
        self::assertLessThan($position($messengerPassUpTo81), $positions[CqrsHandlerPass::class]);

        $after = array_map(static fn (string $class): int => $positions[$class], self::AFTER_MESSENGER_PASS);
        foreach ($after as $index => $passPosition) {
            $class = self::AFTER_MESSENGER_PASS[$index];
            self::assertGreaterThan($position($messengerPassUpTo81), $passPosition, $class.' must run after MessengerPass of Symfony 8.1.');
            self::assertGreaterThan($position($messengerPassFrom82), $passPosition, $class.' must run after MessengerPass of Symfony 8.2.');
            self::assertLessThan($position($symfonyLatePasses), $passPosition, $class.' must run before Symfony\'s passes at -32.');
        }
        $sorted = $after;
        sort($sorted);
        self::assertSame($sorted, $after, 'The passes after MessengerPass keep their order.');
    }

    private static function marker(): CompilerPassInterface
    {
        return new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
            }
        };
    }
}
