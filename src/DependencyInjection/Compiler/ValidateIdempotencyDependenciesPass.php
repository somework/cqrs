<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/** @internal */
final class ValidateIdempotencyDependenciesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.idempotency.enabled')) {
            return;
        }

        if (true !== $container->getParameter('somework_cqrs.idempotency.enabled')) {
            return;
        }

        if (class_exists(DeduplicateStamp::class)) {
            return;
        }

        $container->log(
            $this,
            'WARNING: Idempotency bridge is enabled but Symfony DeduplicateStamp is not available. '
            .'Install symfony/lock (composer require symfony/lock) and ensure symfony/messenger ^7.3 '
            .'for deduplication support. IdempotencyStamp will remain a convention marker without enforcement.',
        );
    }
}
