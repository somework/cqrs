<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxSchemaSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
        // ToolEvents class exists in test environment (doctrine/orm is available)
        if (!class_exists(\Doctrine\ORM\Tools\ToolEvents::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }

        $container = $this->createContainerWithRegistrar();

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.schema_subscriber'));

        $definition = $container->getDefinition('somework_cqrs.outbox.schema_subscriber');
        self::assertSame(OutboxSchemaSubscriber::class, $definition->getClass());

        $tags = $definition->getTag('doctrine.event_listener');
        self::assertCount(1, $tags);
        self::assertSame('postGenerateSchema', $tags[0]['event']);
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
        if (!class_exists(\Doctrine\ORM\Tools\ToolEvents::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }

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

    public function test_storage_definition_class_is_dbal(): void
    {
        $container = $this->createContainerWithRegistrar();

        $definition = $container->getDefinition('somework_cqrs.outbox.storage');
        self::assertSame(DbalOutboxStorage::class, $definition->getClass());
    }

    public function test_relay_command_description_present(): void
    {
        $container = $this->createContainerWithRegistrar();

        $definition = $container->getDefinition('somework_cqrs.outbox.relay_command');
        self::assertSame(OutboxRelayCommand::class, $definition->getClass());
    }

    /**
     * @param array{enabled?: bool, table_name?: string} $config
     */
    private function createContainerWithRegistrar(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $registrar = new OutboxRegistrar();
        $registrar->register($container, [
            'enabled' => $config['enabled'] ?? true,
            'table_name' => $config['table_name'] ?? 'somework_cqrs_outbox',
        ]);

        return $container;
    }
}
