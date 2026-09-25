<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function is_array;
use function is_string;

/**
 * Adds AllowNoHandlerMiddleware to the event buses so events without handlers are not an error.
 *
 * @internal
 */
final class AllowNoHandlerMiddlewarePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.allow_no_handler';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::MIDDLEWARE_ID) || !$container->hasParameter('somework_cqrs.allow_no_handler.bus_ids')) {
            return;
        }

        $busIds = $container->getParameter('somework_cqrs.allow_no_handler.bus_ids');
        if (!is_array($busIds)) {
            return;
        }

        // The events that have handlers: only those are worth a warning when a worker receives one
        // without a handler on its bus (an event may have no subscribers at all).
        $metadata = $container->hasParameter('somework_cqrs.handler_metadata') ? $container->getParameter('somework_cqrs.handler_metadata') : [];
        $handledEvents = [];
        foreach (is_array($metadata) && is_array($metadata['event'] ?? null) ? $metadata['event'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['message'] ?? null)) {
                $handledEvents[$entry['message']] = true;
            }
        }
        $container->getDefinition(self::MIDDLEWARE_ID)->setArgument('$handledEvents', array_keys($handledEvents));

        foreach ($busIds as $busId) {
            if (is_string($busId) && '' !== $busId) {
                MessengerMiddlewareInjector::inject($container, $busId, self::MIDDLEWARE_ID);
            }
        }
    }
}
