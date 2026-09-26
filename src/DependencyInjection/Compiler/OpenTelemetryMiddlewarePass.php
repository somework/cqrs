<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use SomeWork\CqrsBundle\Messenger\TraceContextCaptureMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;

/**
 * Registers OpenTelemetryMiddleware on the CQRS buses when a TracerProviderInterface
 * service is available.
 *
 * @internal
 */
final class OpenTelemetryMiddlewarePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.open_telemetry';

    public const CAPTURE_MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.trace_context_capture';

    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(TracerProviderInterface::class) || !$container->has(TracerProviderInterface::class)) {
            return;
        }

        $busIds = CqrsBusIds::resolve($container);
        if ([] === $busIds) {
            return;
        }

        // Positional argument: the definition is created after ResolveNamedArgumentsPass may have run.
        $container->setDefinition(self::MIDDLEWARE_ID, (new Definition(OpenTelemetryMiddleware::class))
            ->setArguments([new Reference(TracerProviderInterface::class)])
            ->setPublic(false));

        $container->setDefinition(self::CAPTURE_MIDDLEWARE_ID, (new Definition(TraceContextCaptureMiddleware::class))->setPublic(false));

        // Messages stored in the outbox continue the trace they were stored in when relayed.
        if ($container->hasDefinition('somework_cqrs.outbox.writer')) {
            $container->getDefinition('somework_cqrs.outbox.writer')->setArgument('$captureTraceContext', true);
        }

        foreach ($busIds as $busId) {
            MessengerMiddlewareInjector::inject($container, $busId, self::MIDDLEWARE_ID);
            // Before dispatch_after_current_bus: deferred messages keep the trace they were dispatched in.
            MessengerMiddlewareInjector::prepend($container, $busId, self::CAPTURE_MIDDLEWARE_ID);
        }
    }
}
