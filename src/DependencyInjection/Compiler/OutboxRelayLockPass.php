<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;

/**
 * Scopes the outbox relay lock to the application: "framework.cache.prefix_seed" when the
 * application sets it, the project directory otherwise.
 *
 * Symfony's default seed also contains the container class, which differs between environments
 * and debug modes: relays started with another APP_ENV or APP_DEBUG must still share the lock.
 * Applications deployed to a new directory per release set prefix_seed to a stable value, so the
 * relays of the old and the new release share it too. FrameworkBundle's parameter is only known
 * once its configuration is merged, hence this pass.
 *
 * @internal
 */
final class OutboxRelayLockPass implements CompilerPassInterface
{
    public const RELAY_ID = 'somework_cqrs.outbox.relay_command';

    /** FrameworkBundle's default for framework.cache.prefix_seed. */
    private const DEFAULT_PREFIX_SEED = '_%kernel.project_dir%.%kernel.container_class%';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::RELAY_ID)) {
            return;
        }

        $definition = $container->getDefinition(self::RELAY_ID);
        $resource = $definition->getArguments()['$lockName'] ?? null;
        if (!is_string($resource)) {
            return;
        }

        $configuredSeed = $container->hasParameter('cache.prefix.seed') ? $container->getParameter('cache.prefix.seed') : null;

        $seed = match (true) {
            null !== $configuredSeed && self::DEFAULT_PREFIX_SEED !== $configuredSeed => '%cache.prefix.seed%',
            $container->hasParameter('kernel.project_dir') => '%kernel.project_dir%',
            default => 'app',
        };

        $definition->setArgument('$lockName', sprintf('somework_cqrs.outbox.relay.%s.%s', $seed, $resource));
    }
}
