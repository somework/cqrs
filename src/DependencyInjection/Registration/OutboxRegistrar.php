<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Command\OutboxFailedCommand;
use SomeWork\CqrsBundle\Command\OutboxPurgeCommand;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxSigningSecretPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoragePass;
use SomeWork\CqrsBundle\Health\OutboxHealthChecker;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Outbox\Signing\SigningOutboxStorage;
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
     * @param array{enabled: bool, table_name: string, storage?: string|null, connection?: string, serializer?: string, auto_setup?: bool, max_attempts?: int|string, signing?: array{enabled: bool, secret: string|null, previous_secrets: list<string>, accept_unsigned: bool|string}} $config
     * @param bool                                                                                                                                                                                                                                                                       $schemaToolAvailable Whether doctrine/orm (schema tool events) is installed
     * @param array<string, string|null>                                                                                                                                                                                                                                                 $buses               The "somework_cqrs.buses" configuration
     */
    public function register(ContainerBuilder $container, array $config, bool $schemaToolAvailable = false, array $buses = [], string $defaultBusId = 'messenger.default_bus', ?ContainerHelper $helper = null): void
    {
        $helper ??= new ContainerHelper();
        $connection = $config['connection'] ?? 'default';
        $customStorage = $config['storage'] ?? null;
        $helper->recordConfiguredService($container, 'outbox.serializer', $config['serializer'] ?? 'messenger.default_serializer');

        if (null === $customStorage) {
            $helper->recordConfiguredService($container, 'outbox.connection', sprintf('doctrine.dbal.%s_connection', $connection));
            $storageDef = new Definition(DbalOutboxStorage::class);
            $storageDef->setArgument('$connection', new Reference(sprintf('doctrine.dbal.%s_connection', $connection)));
            $storageDef->setArgument('$tableName', $config['table_name']);
            $storageDef->setArgument('$autoSetup', $config['auto_setup'] ?? true);
            $storageDef->setPublic(false);
            $container->setDefinition(OutboxStoragePass::DBAL_STORAGE_ID, $storageDef);
            $baseStorage = OutboxStoragePass::DBAL_STORAGE_ID;
        } else {
            $baseStorage = $helper->configuredService($container, 'outbox.storage', $customStorage);
        }

        // The storage the application uses (decorate or replace it) is an alias of the base
        // storage, which the setup and failed commands and the health check keep using behind a
        // decorator (see OutboxStoragePass).
        $container->setAlias(OutboxStoragePass::BASE_STORAGE_ID, $baseStorage)->setPublic(false);
        $container->setAlias(OutboxStoragePass::STORAGE_ID, $baseStorage)->setPublic(false);
        $container->setAlias(OutboxStorage::class, OutboxStoragePass::STORAGE_ID)->setPublic(false);
        // The capabilities autowire to the configured storage (OutboxStoragePass drops the ones it
        // does not implement). There is no alias of DbalOutboxStorage: storing through it would
        // bypass the decorators, signing included.
        foreach (OutboxStoragePass::CAPABILITIES as $capability) {
            $container->setAlias($capability, OutboxStoragePass::BASE_STORAGE_ID)->setPublic(false);
        }

        // Signing decorates the storage the application uses, so rows of any storage are signed; the
        // highest priority makes it the innermost decorator, which stores what it signed.
        $signing = $config['signing'] ?? ['enabled' => false, 'secret' => null, 'previous_secrets' => [], 'accept_unsigned' => false];
        $signer = null;
        if (true === $signing['enabled']) {
            $signerDef = new Definition(OutboxSigner::class);
            $signerDef->setArgument('$secret', $signing['secret']);
            $signerDef->setArgument('$previousSecrets', $signing['previous_secrets']);
            $signerDef->setPublic(false);
            $container->setDefinition(OutboxSigningSecretPass::SIGNER_ID, $signerDef);
            $signer = new Reference(OutboxSigningSecretPass::SIGNER_ID);

            $signingDef = new Definition(SigningOutboxStorage::class);
            $signingDef->setDecoratedService(OutboxStoragePass::STORAGE_ID, null, 1000);
            $signingDef->setArgument('$inner', new Reference('somework_cqrs.outbox.signing_storage.inner'));
            $signingDef->setArgument('$signer', $signer);
            $signingDef->setPublic(false);
            $container->setDefinition('somework_cqrs.outbox.signing_storage', $signingDef);
        }

        $serializer = new Reference($config['serializer'] ?? 'messenger.default_serializer');
        $container->setAlias('somework_cqrs.outbox.serializer', (string) $serializer)->setPublic(false);

        $writerDef = new Definition(OutboxWriter::class);
        $writerDef->setArgument('$storage', new Reference(OutboxStoragePass::STORAGE_ID));
        $writerDef->setArgument('$serializer', $serializer);
        $writerDef->setArgument('$transports', new Reference('somework_cqrs.stamp_decider.message_transport', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $writerDef->setArgument('$causation', new Reference('somework_cqrs.causation_id_context', ContainerInterface::NULL_ON_INVALID_REFERENCE));
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
        $relayDef->setArgument('$lockName', null === $customStorage ? sprintf('%s.%s', $connection, $config['table_name']) : 'storage.'.$customStorage);
        $relayDef->setArgument('$maxAttempts', $config['max_attempts'] ?? 10);
        $relayDef->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        // Messenger's transports by name: a row stored for a transport that does not exist is given up at once.
        $relayDef->setArgument('$transports', new Reference('messenger.receiver_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $relayDef->setArgument('$signer', $signer);
        $relayDef->setArgument('$acceptUnsigned', $signing['accept_unsigned']);
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
        $failedDef->setArgument('$signer', $signer);
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

        if ($schemaToolAvailable && null === $customStorage) {
            $subscriberDef = new Definition(OutboxSchemaSubscriber::class);
            $subscriberDef->setArgument('$tableName', $config['table_name']);
            // Only the schema of the outbox connection gets the table (e.g. in generated migrations).
            $subscriberDef->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $connection]);
            $subscriberDef->setPublic(false);
            $container->setDefinition('somework_cqrs.outbox.schema_subscriber', $subscriberDef);
        }
    }
}
