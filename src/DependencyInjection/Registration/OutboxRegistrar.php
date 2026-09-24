<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Command\OutboxPurgeCommand;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class OutboxRegistrar
{
    /**
     * @param array{enabled: bool, table_name: string, connection?: string, serializer?: string, auto_setup?: bool} $config
     * @param bool                                                                                                  $schemaToolAvailable Whether doctrine/orm (schema tool events) is installed
     * @param array<string, string|null>                                                                            $buses               The "somework_cqrs.buses" configuration
     */
    public function register(ContainerBuilder $container, array $config, bool $schemaToolAvailable = false, array $buses = [], string $defaultBusId = 'messenger.default_bus'): void
    {
        $connection = $config['connection'] ?? 'default';

        $storageDef = new Definition(DbalOutboxStorage::class);
        $storageDef->setArgument('$connection', new Reference(sprintf('doctrine.dbal.%s_connection', $connection)));
        $storageDef->setArgument('$tableName', $config['table_name']);
        $storageDef->setArgument('$autoSetup', $config['auto_setup'] ?? true);
        $storageDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.storage', $storageDef);
        $container->setAlias(OutboxStorage::class, 'somework_cqrs.outbox.storage')->setPublic(false);
        $container->setAlias(DbalOutboxStorage::class, 'somework_cqrs.outbox.storage')->setPublic(false);

        $serializer = new Reference($config['serializer'] ?? 'messenger.default_serializer');
        $container->setAlias('somework_cqrs.outbox.serializer', (string) $serializer)->setPublic(false);

        $relayDef = new Definition(OutboxRelayCommand::class);
        $relayDef->setArgument('$outboxStorage', new Reference('somework_cqrs.outbox.storage'));
        $relayDef->setArgument('$serializer', $serializer);
        $relayDef->setArgument('$messageBus', new Reference($defaultBusId));
        $relayDef->setArgument('$lockFactory', new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        // Relayed messages go through the bus of their type, so workers route them to the right bus.
        $relayDef->setArgument('$buses', ServiceLocatorTagPass::register($container, [
            'command' => new Reference($buses['command_async'] ?? $buses['command'] ?? $defaultBusId),
            'query' => new Reference($buses['query'] ?? $defaultBusId),
            'event' => new Reference($buses['event_async'] ?? $buses['event'] ?? $defaultBusId),
        ]));
        // Scoped to the application and the table: relays of other projects on the same host must not block it.
        $relayDef->setArgument('$lockName', sprintf(
            'somework_cqrs.outbox.relay.%s.%s.%s',
            $container->hasParameter('kernel.project_dir') ? '%kernel.project_dir%' : 'app',
            $connection,
            $config['table_name'],
        ));
        $relayDef->addTag('console.command');
        $relayDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.relay_command', $relayDef);

        $setupDef = new Definition(OutboxSetupCommand::class);
        $setupDef->setArgument('$outboxStorage', new Reference('somework_cqrs.outbox.storage'));
        $setupDef->addTag('console.command');
        $setupDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.setup_command', $setupDef);

        $purgeDef = new Definition(OutboxPurgeCommand::class);
        $purgeDef->setArgument('$outboxStorage', new Reference('somework_cqrs.outbox.storage'));
        $purgeDef->addTag('console.command');
        $purgeDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.purge_command', $purgeDef);

        if ($schemaToolAvailable) {
            $subscriberDef = new Definition(OutboxSchemaSubscriber::class);
            $subscriberDef->setArgument('$tableName', $config['table_name']);
            // Only the schema of the outbox connection gets the table (e.g. in generated migrations).
            $subscriberDef->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $connection]);
            $subscriberDef->setPublic(false);
            $container->setDefinition('somework_cqrs.outbox.schema_subscriber', $subscriberDef);
        }
    }
}
