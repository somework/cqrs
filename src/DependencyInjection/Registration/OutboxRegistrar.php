<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use Doctrine\ORM\Tools\ToolEvents;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** @internal */
final class OutboxRegistrar
{
    /**
     * @param array{enabled: bool, table_name: string} $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        $storageDef = new Definition(DbalOutboxStorage::class);
        $storageDef->setArgument('$connection', new Reference('doctrine.dbal.default_connection'));
        $storageDef->setArgument('$tableName', $config['table_name']);
        $storageDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.storage', $storageDef);
        $container->setAlias(OutboxStorage::class, 'somework_cqrs.outbox.storage')->setPublic(false);

        $relayDef = new Definition(OutboxRelayCommand::class);
        $relayDef->setArgument('$outboxStorage', new Reference('somework_cqrs.outbox.storage'));
        $relayDef->setArgument('$serializer', new Reference('messenger.default_serializer'));
        $relayDef->setArgument('$messageBus', new Reference('messenger.default_bus'));
        $relayDef->addTag('console.command');
        $relayDef->setPublic(false);
        $container->setDefinition('somework_cqrs.outbox.relay_command', $relayDef);

        if (class_exists(ToolEvents::class)) {
            $subscriberDef = new Definition(OutboxSchemaSubscriber::class);
            $subscriberDef->setArgument('$tableName', $config['table_name']);
            $subscriberDef->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']);
            $subscriberDef->setPublic(false);
            $container->setDefinition('somework_cqrs.outbox.schema_subscriber', $subscriberDef);
        }
    }
}
