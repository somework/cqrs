<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Command\OutboxFailedCommand;
use SomeWork\CqrsBundle\Command\OutboxPurgeCommand;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoragePass;
use SomeWork\CqrsBundle\Health\OutboxHealthChecker;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
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
     * @param array{enabled: bool, table_name: string, connection?: string, serializer?: string, auto_setup?: bool, max_attempts?: int|string} $config
     * @param bool                                                                                                                             $schemaToolAvailable Whether doctrine/orm (schema tool events) is installed
     * @param array<string, string|null>                                                                                                       $buses               The "somework_cqrs.buses" configuration
     */
    public function register(ContainerBuilder $container, array $config, bool $schemaToolAvailable = false, array $buses = [], string $defaultBusId = 'messenger.default_bus'): void
    {
        $connection = $config['connection'] ?? 'default';

        $storageDef = new Definition(DbalOutboxStorage::class);
        $storageDef->setArgument('$connection', new Reference(sprintf('doctrine.dbal.%s_connection', $connection)));
        $storageDef->setArgument('$tableName', $config['table_name']);
        $storageDef->setArgument('$autoSetup', $config['auto_setup'] ?? true);
        $storageDef->setPublic(false);
        // The storage the application uses (decorate or replace it) is an alias of the DBAL
        // storage, which the setup and failed commands and the health check keep using behind a
        // decorator (see OutboxStoragePass).
        $container->setDefinition(OutboxStoragePass::DBAL_STORAGE_ID, $storageDef);
        $container->setAlias(OutboxStoragePass::STORAGE_ID, OutboxStoragePass::DBAL_STORAGE_ID)->setPublic(false);
        $container->setAlias(OutboxStorage::class, OutboxStoragePass::STORAGE_ID)->setPublic(false);
        $container->setAlias(DbalOutboxStorage::class, OutboxStoragePass::DBAL_STORAGE_ID)->setPublic(false);

        $serializer = new Reference($config['serializer'] ?? 'messenger.default_serializer');
        $container->setAlias('somework_cqrs.outbox.serializer', (string) $serializer)->setPublic(false);

        $writerDef = new Definition(OutboxWriter::class);
        $writerDef->setArgument('$storage', new Reference(OutboxStoragePass::STORAGE_ID));
        $writerDef->setArgument('$serializer', $serializer);
        $writerDef->setArgument('$transports', new Reference('somework_cqrs.stamp_decider.message_transport', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $writerDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.writer', $writerDef);
        $container->setAlias(OutboxWriter::class, 'somework_cqrs.outbox.writer')->setPublic(false);

        $relayDef = new Definition(OutboxRelayCommand::class);
        $relayDef->setArgument('$outboxStorage', new Reference(OutboxStoragePass::STORAGE_ID));
        $relayDef->setArgument('$serializer', $serializer);
        $relayDef->setArgument('$messageBus', new Reference($defaultBusId));
        $relayDef->setArgument('$lockFactory', new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        // Relayed messages go through the bus of their type, so workers route them to the right bus.
        $relayDef->setArgument('$buses', ServiceLocatorTagPass::register($container, [
            'command' => new Reference($buses['command_async'] ?? $buses['command'] ?? $defaultBusId),
            'query' => new Reference($buses['query'] ?? $defaultBusId),
            'event' => new Reference($buses['event_async'] ?? $buses['event'] ?? $defaultBusId),
        ]));
        // Scoped to the connection and the table here, and to the application by OutboxRelayLockPass:
        // relays of other projects sharing the lock store must not block it.
        $relayDef->setArgument('$lockName', sprintf('%s.%s', $connection, $config['table_name']));
        $relayDef->setArgument('$maxAttempts', $config['max_attempts'] ?? 10);
        $relayDef->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        // Messenger's transports by name: a row stored for a transport that does not exist is given up at once.
        $relayDef->setArgument('$transports', new Reference('messenger.receiver_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $relayDef->addTag('console.command');
        $relayDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.relay_command', $relayDef);

        $setupDef = new Definition(OutboxSetupCommand::class);
        $setupDef->setArgument('$outboxStorage', new Reference(OutboxStoragePass::STORAGE_ID));
        $setupDef->addTag('console.command');
        $setupDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.setup_command', $setupDef);

        $failedDef = new Definition(OutboxFailedCommand::class);
        $failedDef->setArgument('$outboxStorage', new Reference(OutboxStoragePass::STORAGE_ID));
        $failedDef->addTag('console.command');
        $failedDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.failed_command', $failedDef);

        $healthDef = new Definition(OutboxHealthChecker::class);
        $healthDef->setArgument('$outboxStorage', new Reference(OutboxStoragePass::STORAGE_ID));
        $healthDef->addTag('somework_cqrs.health_checker');
        $healthDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.health_checker', $healthDef);

        $purgeDef = new Definition(OutboxPurgeCommand::class);
        $purgeDef->setArgument('$outboxStorage', new Reference(OutboxStoragePass::STORAGE_ID));
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
