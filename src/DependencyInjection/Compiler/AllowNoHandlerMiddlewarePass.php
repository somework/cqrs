<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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

        foreach ($busIds as $busId) {
            if (is_string($busId) && '' !== $busId) {
                MessengerMiddlewareInjector::inject($container, $busId, self::MIDDLEWARE_ID);
            }
        }
    }
}
