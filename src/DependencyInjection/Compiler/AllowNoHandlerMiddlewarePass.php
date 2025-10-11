<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_unshift;
use function is_array;
use function is_string;

final class AllowNoHandlerMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.allow_no_handler.bus_ids')) {
            return;
        }

        $busIds = $container->getParameter('somework_cqrs.allow_no_handler.bus_ids');
        if (!is_array($busIds) || [] === $busIds) {
            return;
        }

        $middlewareReference = new Reference('somework_cqrs.messenger.middleware.allow_no_handler');

        foreach ($busIds as $busId) {
            if (!is_string($busId)) {
                continue;
            }

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
     * @param list<Reference> $middlewares
     */
    private function hasMiddlewareReference(array $middlewares, Reference $middleware): bool
    {
        foreach ($middlewares as $registered) {
            if ($registered == $middleware) {
                return true;
            }
        }

        return false;
    }

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
