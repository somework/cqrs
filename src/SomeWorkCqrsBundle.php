<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle;

use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CausationIdMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsRetryStrategyPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DeduplicationLockReleasePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DoctrineEventsPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\EnvelopeAwareHandlersLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\HealthCheckerLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\LoggerChannelPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\MessengerMiddlewareInjector;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OpenTelemetryMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxRelayLockPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxSigningSecretPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoragePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoreMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\RemoveHandlerMetadataParameterPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\TransportRoutingPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateBusIdsPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateConfiguredServicesPass;
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

        // Before the passes that use them: the services the configuration names must exist.
        $container->addCompilerPass(new ValidateConfiguredServicesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        // Configured bus ids must be Messenger buses before handlers are registered on them.
        $container->addCompilerPass(new ValidateBusIdsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 2);
        // Before Symfony's MessengerPass (priority 0, -16 from Symfony 8.2): normalises handler tags and buses.
        $container->addCompilerPass(new CqrsHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        // Only read what the extensions registered, whether MessengerPass ran or not.
        $container->addCompilerPass(new CqrsRetryStrategyPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new OutboxRelayLockPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new OutboxStoragePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new OutboxSigningSecretPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        // Only reads DoctrineBundle's parameters: the "doctrine.event_listener" tag needs DoctrineBundle.
        $container->addCompilerPass(new DoctrineEventsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new ValidateIdempotencyDependenciesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1);

        // After MessengerPass, whatever priority the Symfony version gives it, and before the
        // optimization passes so references to aliases (tracer provider, lock factory) resolve.
        // MessengerPass registers the handlers locators these passes decorate, builds the bus
        // middleware lists from the "<bus>.middleware" parameters, and adds the routing of
        // #[AsMessageHandler(transport: ...)] (Symfony 8.2).
        $afterMessengerPass = MessengerMiddlewareInjector::AFTER_MESSENGER_PASS;
        $container->addCompilerPass(new EnvelopeAwareHandlersLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        // Gives the health checkers access to the private handler and transport services.
        $container->addCompilerPass(new HealthCheckerLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        // Each middleware pass inserts right after "dispatch_after_current_bus", so the resulting
        // order is: OpenTelemetry, CausationId, AllowNoHandler, then Messenger's own middleware; the
        // deduplication lock release goes right after Messenger's "deduplicate_middleware", and the
        // outbox store (DispatchMode::OUTBOX) right before "send_message".
        $container->addCompilerPass(new AllowNoHandlerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        $container->addCompilerPass(new CausationIdMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        $container->addCompilerPass(new OpenTelemetryMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        $container->addCompilerPass(new DeduplicationLockReleasePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        $container->addCompilerPass(new OutboxStoreMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        // Read the complete routing, including the routes handlers add.
        $container->addCompilerPass(new TransportRoutingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        $container->addCompilerPass(new ValidateTransportNamesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass);
        // After every pass of the bundle that adds a service with a logger.
        $container->addCompilerPass(new LoggerChannelPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, $afterMessengerPass - 1);
        // Once every pass read it and the registry received it (parameters are resolved by then).
        $container->addCompilerPass(new RemoveHandlerMetadataParameterPass(), PassConfig::TYPE_AFTER_REMOVING);
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
