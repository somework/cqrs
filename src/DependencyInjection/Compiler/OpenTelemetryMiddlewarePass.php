<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use SomeWork\CqrsBundle\Messenger\OpenTelemetryMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_unshift;
use function array_values;
use function interface_exists;
use function is_string;

/**
 * Conditionally registers and prepends OpenTelemetryMiddleware to all Messenger buses.
 *
 * The pass only activates when:
 * 1. The OpenTelemetry API is installed (class_exists check)
 * 2. A TracerProviderInterface service is registered in the container
 *
 * @internal
 */
final class OpenTelemetryMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(TracerProviderInterface::class)) {
            return;
        }

        if (!$container->has(TracerProviderInterface::class)) {
            return;
        }

        if (!$container->hasParameter('somework_cqrs.default_bus')) {
            return;
        }

        $definition = new Definition(OpenTelemetryMiddleware::class);
        $definition->setArgument('$tracerProvider', new Reference(TracerProviderInterface::class));
        $definition->setPublic(false);
        $container->setDefinition('somework_cqrs.messenger.middleware.open_telemetry', $definition);

        $busIds = $this->collectBusIds($container);
        $middlewareReference = new Reference('somework_cqrs.messenger.middleware.open_telemetry');

        foreach ($busIds as $busId) {
            $busDefinition = $this->resolveMessageBusDefinition($container, $busId);

            if (null === $busDefinition) {
                continue;
            }

            $arguments = $busDefinition->getArguments();

            if (!array_key_exists(0, $arguments)) {
                continue;
            }

            $argument = $arguments[0];

            if (!$argument instanceof IteratorArgument) {
                continue;
            }

            $middlewares = $argument->getValues();

            if ($this->hasMiddlewareReference($middlewares, $middlewareReference)) {
                continue;
            }

            array_unshift($middlewares, $middlewareReference);

            $busDefinition->replaceArgument(0, new IteratorArgument($middlewares));
        }
    }

    /**
     * @return list<string>
     */
    private function collectBusIds(ContainerBuilder $container): array
    {
        $busIds = [];

        /** @var string $defaultBus */
        $defaultBus = $container->getParameter('somework_cqrs.default_bus');
        $busIds[] = $defaultBus;

        foreach (['command', 'query', 'event', 'command_async', 'event_async'] as $key) {
            $paramName = 'somework_cqrs.bus.'.$key;
            if (!$container->hasParameter($paramName)) {
                continue;
            }

            $value = $container->getParameter($paramName);
            if (is_string($value) && '' !== $value) {
                $busIds[] = $value;
            }
        }

        return array_values(array_unique($busIds));
    }

    /**
     * @param array<int|string, Reference> $middlewares
     */
    private function hasMiddlewareReference(array $middlewares, Reference $middleware): bool
    {
        foreach ($middlewares as $registered) {
            if ((string) $registered === (string) $middleware) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $visited */
    private function resolveMessageBusDefinition(ContainerBuilder $container, string $serviceId, array $visited = []): ?Definition
    {
        if (array_key_exists($serviceId, $visited)) {
            return null;
        }

        $visited[$serviceId] = true;

        if (!$container->has($serviceId)) {
            return null;
        }

        $definition = $container->findDefinition($serviceId);
        $arguments = $definition->getArguments();

        if (!array_key_exists(0, $arguments)) {
            return null;
        }

        $argument = $arguments[0];

        if ($argument instanceof IteratorArgument) {
            return $definition;
        }

        if ($argument instanceof Reference) {
            return $this->resolveMessageBusDefinition($container, (string) $argument, $visited);
        }

        return null;
    }
}
