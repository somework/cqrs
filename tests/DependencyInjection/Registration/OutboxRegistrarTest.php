<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxFailedCommand;
use SomeWork\CqrsBundle\Command\OutboxPurgeCommand;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(OutboxRegistrar::class)]
final class OutboxRegistrarTest extends TestCase
{
    public function test_registers_dbal_outbox_storage(): void
    {
        $container = $this->createContainerWithRegistrar();

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.storage'));

        $definition = $container->getDefinition('somework_cqrs.outbox.storage');
        self::assertSame(DbalOutboxStorage::class, $definition->getClass());
    }

    public function test_registers_outbox_storage_alias(): void
    {
        $container = $this->createContainerWithRegistrar();

        self::assertTrue($container->hasAlias(OutboxStorage::class));
        self::assertSame(
            'somework_cqrs.outbox.storage',
            (string) $container->getAlias(OutboxStorage::class),
        );
    }

    public function test_registers_relay_command(): void
    {
        $container = $this->createContainerWithRegistrar();

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.relay_command'));

        $definition = $container->getDefinition('somework_cqrs.outbox.relay_command');
        self::assertSame(OutboxRelayCommand::class, $definition->getClass());

        $tags = $definition->getTag('console.command');
        self::assertCount(1, $tags);
    }

    public function test_registers_schema_subscriber_when_orm_available(): void
    {
        $container = $this->createContainerWithRegistrar();

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.schema_subscriber'));

        $definition = $container->getDefinition('somework_cqrs.outbox.schema_subscriber');
        self::assertSame(OutboxSchemaSubscriber::class, $definition->getClass());

        $tags = $definition->getTag('doctrine.event_listener');
        self::assertCount(1, $tags);
        self::assertSame('postGenerateSchema', $tags[0]['event']);
        self::assertSame('default', $tags[0]['connection']);
    }

    public function test_uses_the_configured_connection_serializer_and_auto_setup(): void
    {
        $container = new ContainerBuilder();
        (new OutboxRegistrar())->register($container, [
            'enabled' => true,
            'table_name' => 'outbox',
            'connection' => 'orders',
            'serializer' => 'app.outbox_serializer',
            'auto_setup' => false,
        ], true);

        $storage = $container->getDefinition('somework_cqrs.outbox.storage');
        self::assertSame('doctrine.dbal.orders_connection', (string) $storage->getArgument('$connection'));
        self::assertFalse($storage->getArgument('$autoSetup'));
        self::assertSame('app.outbox_serializer', (string) $container->getAlias('somework_cqrs.outbox.serializer'));
        self::assertSame('app.outbox_serializer', (string) $container->getDefinition('somework_cqrs.outbox.relay_command')->getArgument('$serializer'));
        self::assertSame('orders', $container->getDefinition('somework_cqrs.outbox.schema_subscriber')->getTag('doctrine.event_listener')[0]['connection']);
    }

    public function test_registers_setup_failed_and_purge_commands(): void
    {
        $container = $this->createContainerWithRegistrar();

        self::assertSame(OutboxFailedCommand::class, $container->getDefinition('somework_cqrs.outbox.failed_command')->getClass());
        self::assertTrue($container->getDefinition('somework_cqrs.outbox.failed_command')->hasTag('console.command'));

        self::assertSame(OutboxSetupCommand::class, $container->getDefinition('somework_cqrs.outbox.setup_command')->getClass());
        self::assertSame(OutboxPurgeCommand::class, $container->getDefinition('somework_cqrs.outbox.purge_command')->getClass());
        self::assertTrue($container->getDefinition('somework_cqrs.outbox.setup_command')->hasTag('console.command'));
        self::assertTrue($container->getDefinition('somework_cqrs.outbox.purge_command')->hasTag('console.command'));
        self::assertSame('somework_cqrs.outbox.storage', (string) $container->getAlias(DbalOutboxStorage::class));
    }

    public function test_the_relay_lock_is_scoped_to_the_connection_and_table(): void
    {
        $container = new ContainerBuilder();
        (new OutboxRegistrar())->register($container, ['enabled' => true, 'table_name' => 'orders_outbox', 'connection' => 'orders']);

        // OutboxRelayLockPass adds the application scope.
        self::assertSame('orders.orders_outbox', $container->getDefinition('somework_cqrs.outbox.relay_command')->getArgument('$lockName'));
    }

    public function test_relay_uses_the_lock_factory_when_available(): void
    {
        $argument = $this->createContainerWithRegistrar()->getDefinition('somework_cqrs.outbox.relay_command')->getArgument('$lockFactory');

        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame('lock.factory', (string) $argument);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $argument->getInvalidBehavior());
    }

    public function test_storage_uses_configured_table_name(): void
    {
        $container = $this->createContainerWithRegistrar(['table_name' => 'custom_outbox_table']);

        $definition = $container->getDefinition('somework_cqrs.outbox.storage');
        self::assertSame('custom_outbox_table', $definition->getArgument('$tableName'));
    }

    public function test_relay_command_has_correct_dependencies(): void
    {
        $container = $this->createContainerWithRegistrar();

        $definition = $container->getDefinition('somework_cqrs.outbox.relay_command');

        $arguments = $definition->getArguments();
        self::assertArrayHasKey('$outboxStorage', $arguments);
        self::assertArrayHasKey('$serializer', $arguments);
        self::assertArrayHasKey('$messageBus', $arguments);

        self::assertSame('somework_cqrs.outbox.storage', (string) $arguments['$outboxStorage']);
        self::assertSame('messenger.default_serializer', (string) $arguments['$serializer']);
        self::assertSame('messenger.default_bus', (string) $arguments['$messageBus']);
    }

    public function test_schema_subscriber_uses_configured_table_name(): void
    {
        $container = $this->createContainerWithRegistrar(['table_name' => 'my_custom_outbox']);

        $definition = $container->getDefinition('somework_cqrs.outbox.schema_subscriber');
        self::assertSame('my_custom_outbox', $definition->getArgument('$tableName'));
    }

    public function test_storage_has_connection_argument(): void
    {
        $container = $this->createContainerWithRegistrar();

        $definition = $container->getDefinition('somework_cqrs.outbox.storage');
        $connection = $definition->getArgument('$connection');
        self::assertSame('doctrine.dbal.default_connection', (string) $connection);
    }

    public function test_skips_schema_subscriber_without_schema_tool(): void
    {
        $container = $this->createContainerWithRegistrar(schemaToolAvailable: false);

        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.schema_subscriber'));
        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.storage'));
    }

    /**
     * @param array{enabled?: bool, table_name?: string} $config
     */
    private function createContainerWithRegistrar(array $config = [], bool $schemaToolAvailable = true): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $registrar = new OutboxRegistrar();
        $registrar->register($container, [
            'enabled' => $config['enabled'] ?? true,
            'table_name' => $config['table_name'] ?? 'somework_cqrs_outbox',
        ], $schemaToolAvailable);

        return $container;
    }
}
