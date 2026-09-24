<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_splice;
use function array_unshift;
use function array_values;
use function str_ends_with;

/**
 * Inserts bundle middleware into the middleware list of Messenger buses.
 *
 * Bundle middleware is placed right after Messenger's "dispatch_after_current_bus"
 * middleware (or first when the bus does not use it). Middleware placed before it would not
 * run for messages that are deferred until the current bus finishes, because those continue
 * with the stack that follows "dispatch_after_current_bus".
 *
 * @internal
 */
final class MessengerMiddlewareInjector
{
    public const AFTER_DISPATCH_AFTER_CURRENT_BUS = 'dispatch_after_current_bus';

    /**
     * @return bool whether the middleware is (now) part of the bus
     */
    public static function inject(
        ContainerBuilder $container,
        string $busId,
        string $middlewareId,
        string $after = self::AFTER_DISPATCH_AFTER_CURRENT_BUS,
        bool $requireAnchor = false,
    ): bool {
        $definition = self::findBusDefinition($container, $busId);

        if (null === $definition) {
            return false;
        }

        $argument = $definition->getArgument(0);
        if (!$argument instanceof IteratorArgument) {
            return false;
        }

        $middlewares = $argument->getValues();
        $position = null;

        foreach ($middlewares as $index => $middleware) {
            $id = (string) $middleware;

            if ($id === $middlewareId) {
                return true;
            }

            if (null === $position && str_ends_with($id, $after)) {
                $position = $index + 1;
            }
        }

        if (null === $position && $requireAnchor) {
            return false;
        }

        $middlewares = array_values($middlewares);
        array_splice($middlewares, $position ?? 0, 0, [new Reference($middlewareId)]);

        $definition->replaceArgument(0, new IteratorArgument($middlewares));

        return true;
    }

    /**
     * Inserts middleware at the top of the bus stack, before Messenger's own middleware.
     *
     * @return bool whether the middleware is (now) part of the bus
     */
    public static function prepend(ContainerBuilder $container, string $busId, string $middlewareId): bool
    {
        $definition = self::findBusDefinition($container, $busId);
        $argument = $definition?->getArgument(0);

        if (!$argument instanceof IteratorArgument) {
            return false;
        }

        $middlewares = array_values($argument->getValues());
        foreach ($middlewares as $middleware) {
            if ((string) $middleware === $middlewareId) {
                return true;
            }
        }

        array_unshift($middlewares, new Reference($middlewareId));
        $definition->replaceArgument(0, new IteratorArgument($middlewares));

        return true;
    }

    /**
     * Finds the definition holding the middleware iterator of a bus, following aliases and
     * decorators such as the TraceableMessageBus registered in debug mode.
     */
    public static function findBusDefinition(ContainerBuilder $container, string $serviceId): ?Definition
    {
        $visited = [];

        while (!array_key_exists($serviceId, $visited) && $container->has($serviceId)) {
            $visited[$serviceId] = true;
            $definition = $container->findDefinition($serviceId);
            $arguments = $definition->getArguments();

            if (!array_key_exists(0, $arguments)) {
                return null;
            }

            if ($arguments[0] instanceof IteratorArgument) {
                return $definition;
            }

            if (!$arguments[0] instanceof Reference) {
                return null;
            }

            $serviceId = (string) $arguments[0];
        }

        return null;
    }
}
