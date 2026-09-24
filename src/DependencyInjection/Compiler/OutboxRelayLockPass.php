<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;

/**
 * Scopes the outbox relay lock to the application with "framework.cache.prefix_seed".
 *
 * The seed defaults to the project directory; applications deployed to a new directory per
 * release set it to a stable value, so the relays of the old and the new release share the lock.
 * It is only known once FrameworkBundle's configuration is merged, hence this pass.
 *
 * @internal
 */
final class OutboxRelayLockPass implements CompilerPassInterface
{
    public const RELAY_ID = 'somework_cqrs.outbox.relay_command';

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

        $seed = match (true) {
            $container->hasParameter('cache.prefix.seed') => '%cache.prefix.seed%',
            $container->hasParameter('kernel.project_dir') => '%kernel.project_dir%',
            default => 'app',
        };

        $definition->setArgument('$lockName', sprintf('somework_cqrs.outbox.relay.%s.%s', $seed, $resource));
    }
}
