<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Messenger\DeduplicationLockReleaseMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers DeduplicationLockReleaseMiddleware right after Messenger's DeduplicateMiddleware
 * on the CQRS buses when the idempotency bridge is active.
 *
 * @internal
 */
final class DeduplicationLockReleasePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.deduplication_lock_release';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('somework_cqrs.stamp_decider.idempotency') || !$container->has('lock.factory')) {
            return;
        }

        $container->setDefinition(self::MIDDLEWARE_ID, (new Definition(DeduplicationLockReleaseMiddleware::class))
            ->setArguments([new Reference('lock.factory')])
            ->setPublic(false));

        foreach (CqrsBusIds::resolve($container) as $busId) {
            MessengerMiddlewareInjector::inject($container, $busId, self::MIDDLEWARE_ID, 'deduplicate_middleware', requireAnchor: true);
        }
    }
}
