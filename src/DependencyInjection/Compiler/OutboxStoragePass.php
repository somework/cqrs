<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Lets the commands and the check that work on the table itself (setup, failed, health, and the
 * relay's report of pending table changes) use the DBAL storage when the application decorates
 * the outbox storage, e.g. to add logging: the decorator is not a DbalOutboxStorage.
 *
 * An application that replaces the storage (another service under "somework_cqrs.outbox.storage")
 * keeps its storage everywhere; those commands then refuse to run.
 *
 * Runs before the optimization passes: decorators are only applied by DecoratorServicePass.
 *
 * @internal
 */
final class OutboxStoragePass implements CompilerPassInterface
{
    public const STORAGE_ID = 'somework_cqrs.outbox.storage';

    public const DBAL_STORAGE_ID = 'somework_cqrs.outbox.dbal_storage';

    private const TABLE_SERVICES = [
        'somework_cqrs.outbox.setup_command' => '$outboxStorage',
        'somework_cqrs.outbox.failed_command' => '$outboxStorage',
        'somework_cqrs.outbox.health_checker' => '$outboxStorage',
        'somework_cqrs.outbox.relay_command' => '$table',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::DBAL_STORAGE_ID)
            || !$container->hasAlias(self::STORAGE_ID)
            || self::DBAL_STORAGE_ID !== (string) $container->getAlias(self::STORAGE_ID)) {
            return;
        }

        foreach (self::TABLE_SERVICES as $id => $argument) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setArgument($argument, new Reference(self::DBAL_STORAGE_ID));
            }
        }
    }
}
