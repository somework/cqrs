<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Messenger\Middleware\AllowNoHandlerMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function is_array;

final class AllowNoHandlerMiddlewareRegistrar
{
    /**
     * @param array{
     *     command?: string|null,
     *     command_async?: string|null,
     *     query?: string|null,
     *     event?: string|null,
     *     event_async?: string|null,
     * } $buses
     */
    public function register(ContainerBuilder $container, array $buses, string $defaultBusId): void
    {
        $serviceId = 'somework_cqrs.messenger.middleware.allow_no_handler';

        if (!$container->hasDefinition($serviceId)) {
            $container->setDefinition(
                $serviceId,
                (new Definition(AllowNoHandlerMiddleware::class))->setPublic(false)
            );
        }

        $busIds = array_filter([
            $buses['event'] ?? $defaultBusId,
            $buses['event_async'] ?? null,
        ]);

        $busIds = array_values(array_unique($busIds));

        if ([] === $busIds) {
            return;
        }

        $parameterName = 'somework_cqrs.allow_no_handler.bus_ids';
        $existing = $container->hasParameter($parameterName) ? $container->getParameter($parameterName) : [];

        $container->setParameter(
            $parameterName,
            array_values(array_unique(array_merge(is_array($existing) ? $existing : [], $busIds)))
        );
    }
}
