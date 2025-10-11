<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBus;

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

            $definition = $this->resolveBusDefinition($container, $busId);
            if (!$definition instanceof Definition) {
                continue;
            }

            $arguments = $definition->getArguments();

            if (!array_key_exists(0, $arguments)) {
                continue;
            }

            $argument = $arguments[0];

            if ($argument instanceof IteratorArgument) {
                $middlewares = $argument->getValues();
            } elseif (is_array($argument)) {
                $middlewares = $argument;
            } else {
                continue;
            }

            if ($this->hasMiddlewareReference($middlewares, $middlewareReference)) {
                continue;
            }

            array_unshift($middlewares, $middlewareReference);

            if ($argument instanceof IteratorArgument) {
                $definition->replaceArgument(0, new IteratorArgument($middlewares));
            } else {
                $definition->replaceArgument(0, $middlewares);
            }
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

    private function resolveBusDefinition(ContainerBuilder $container, string $serviceId): ?Definition
    {
        if (!$container->hasDefinition($serviceId) && !$container->hasAlias($serviceId)) {
            return null;
        }

        $visited = [];

        while (true) {
            /** @var Definition $definition */
            $definition = $container->findDefinition($serviceId);

            $class = $definition->getClass();
            if (is_string($class) && is_a($class, MessageBus::class, true)) {
                return $definition;
            }

            $arguments = $definition->getArguments();
            if (!array_key_exists(0, $arguments)) {
                return null;
            }

            $argument = $arguments[0];
            if (!$argument instanceof Reference) {
                return null;
            }

            $serviceId = (string) $argument;

            if (isset($visited[$serviceId])) {
                return null;
            }

            $visited[$serviceId] = true;

            if (!$container->hasDefinition($serviceId) && !$container->hasAlias($serviceId)) {
                return null;
            }
        }
    }
}
