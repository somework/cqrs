<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle;

use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CausationIdMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsRetryStrategyPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DeduplicationLockReleasePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\EnvelopeAwareHandlersLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\HealthCheckerLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OpenTelemetryMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxRelayLockPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\TransportRoutingPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateBusIdsPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateHandlerCountPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateIdempotencyDependenciesPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** @api The bundle class registered in config/bundles.php. */
final class SomeWorkCqrsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Configured bus ids must be Messenger buses before handlers are registered on them.
        $container->addCompilerPass(new ValidateBusIdsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 2);
        // Before Symfony's MessengerPass (priority 0): normalises handler tags and buses.
        $container->addCompilerPass(new CqrsHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        // After MessengerPass: decorates the handlers locators it registers.
        $container->addCompilerPass(new EnvelopeAwareHandlersLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        // After CqrsHandlerPass: gives the health checkers access to the private handler and transport services.
        $container->addCompilerPass(new HealthCheckerLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        // Middleware passes run after MessengerPass has built the bus middleware lists, and before
        // the optimization passes so references to aliases (tracer provider, lock factory) resolve.
        // Each inserts right after "dispatch_after_current_bus", so the resulting order is:
        // OpenTelemetry, CausationId, AllowNoHandler, then Messenger's own middleware.
        $container->addCompilerPass(new AllowNoHandlerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        $container->addCompilerPass(new CausationIdMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        $container->addCompilerPass(new OpenTelemetryMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        $container->addCompilerPass(new DeduplicationLockReleasePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        $container->addCompilerPass(new CqrsRetryStrategyPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new TransportRoutingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new OutboxRelayLockPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new ValidateIdempotencyDependenciesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1);
        $container->addCompilerPass(new ValidateTransportNamesPass());
        $container->addCompilerPass(new ValidateHandlerCountPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new CqrsExtension();
        }

        return $this->extension;
    }
}
