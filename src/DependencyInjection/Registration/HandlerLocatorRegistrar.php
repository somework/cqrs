<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_unique;
use function array_values;
use function md5;
use function sprintf;

final class HandlerLocatorRegistrar
{
    /**
     * @param array{command?: string|null, command_async?: string|null, query?: string|null, event?: string|null, event_async?: string|null} $buses
     */
    public function register(ContainerBuilder $container, array $buses, string $defaultBusId): void
    {
        $busIds = array_filter([
            $buses['command'] ?? $defaultBusId,
            $buses['command_async'] ?? null,
            $buses['query'] ?? $defaultBusId,
            $buses['event'] ?? $defaultBusId,
            $buses['event_async'] ?? null,
        ]);

        $busIds = array_values(array_unique($busIds));

        foreach ($busIds as $busId) {
            $resolvedBusId = $this->resolveBusServiceId($container, $busId);
            $locatorId = sprintf('%s.messenger.handlers_locator', $resolvedBusId);

            $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', md5($locatorId));

            $container->register($decoratorId, EnvelopeAwareHandlersLocator::class)
                ->setDecoratedService($locatorId)
                ->setArgument('$decorated', new Reference($decoratorId.'.inner'));
        }
    }

    private function resolveBusServiceId(ContainerBuilder $container, string $busId): string
    {
        while ($container->hasAlias($busId)) {
            $busId = (string) $container->getAlias($busId);
        }

        return $busId;
    }
}
