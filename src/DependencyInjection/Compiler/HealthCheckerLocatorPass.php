<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Health\HandlerResolvabilityChecker;
use SomeWork\CqrsBundle\Health\TransportValidityChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;
use function ksort;

/**
 * Gives the health checkers access to the (private) handler and transport services.
 *
 * Handler and transport services are private, so the checkers cannot look them up in the
 * container at runtime; they receive dedicated service locators instead.
 *
 * Runs after CqrsHandlerPass, which records the handler metadata.
 *
 * @internal
 */
final class HealthCheckerLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(HandlerResolvabilityChecker::class)) {
            $container->getDefinition(HandlerResolvabilityChecker::class)
                ->setArgument('$handlers', ServiceLocatorTagPass::register($container, self::handlerServices($container)));
        }

        if ($container->hasDefinition(TransportValidityChecker::class)) {
            $container->getDefinition(TransportValidityChecker::class)
                ->setArgument('$transports', ServiceLocatorTagPass::register($container, self::transportServices($container)));
        }
    }

    /**
     * @return array<string, Reference>
     */
    private static function handlerServices(ContainerBuilder $container): array
    {
        $metadata = $container->hasParameter('somework_cqrs.handler_metadata')
            ? $container->getParameter('somework_cqrs.handler_metadata')
            : [];

        $services = [];
        foreach (is_array($metadata) ? $metadata : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $serviceId = is_array($entry) ? ($entry['service_id'] ?? null) : null;

                if (is_string($serviceId) && $container->has($serviceId)) {
                    $services[$serviceId] = new Reference($serviceId);
                }
            }
        }

        return $services;
    }

    /**
     * @return array<string, Reference> transport services keyed by transport name
     */
    private static function transportServices(ContainerBuilder $container): array
    {
        $services = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $serviceId => $tags) {
            foreach ($tags as $attributes) {
                $name = $attributes['alias'] ?? $serviceId;
                $services[is_string($name) ? $name : $serviceId] = new Reference($serviceId);
            }
        }

        ksort($services);

        return $services;
    }
}
