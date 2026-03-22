<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_key_exists;
use function array_unshift;
use function array_values;
use function in_array;
use function is_string;

/**
 * Prepends CausationIdMiddleware to all configured Messenger buses.
 *
 * This ensures that any message dispatched through any bus pushes its
 * correlation ID onto the CausationIdContext stack, enabling automatic
 * causation ID propagation for child messages.
 */
/** @internal */
final class CausationIdMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('somework_cqrs.messenger.middleware.causation_id')) {
            return;
        }

        if (!$container->hasParameter('somework_cqrs.default_bus')) {
            return;
        }

        if ($container->hasParameter('somework_cqrs.causation_id.enabled')
            && true !== $container->getParameter('somework_cqrs.causation_id.enabled')) {
            return;
        }

        $busIds = $this->collectBusIds($container);

        /** @var list<string> $allowedBuses */
        $allowedBuses = $container->hasParameter('somework_cqrs.causation_id.buses')
            ? $container->getParameter('somework_cqrs.causation_id.buses')
            : [];

        if ([] !== $allowedBuses) {
            $busIds = array_values(array_filter(
                $busIds,
                static fn (string $busId): bool => in_array($busId, $allowedBuses, true),
            ));
        }

        if ([] === $busIds) {
            return;
        }

        $middlewareReference = new Reference('somework_cqrs.messenger.middleware.causation_id');

        foreach ($busIds as $busId) {
            $definition = $this->resolveMessageBusDefinition($container, $busId);

            if (null === $definition) {
                continue;
            }

            $arguments = $definition->getArguments();

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

            $definition->replaceArgument(0, new IteratorArgument($middlewares));
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
