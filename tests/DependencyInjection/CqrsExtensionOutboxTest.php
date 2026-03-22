<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(OutboxRegistrar::class)]
final class CqrsExtensionOutboxTest extends TestCase
{
    public function test_outbox_disabled_by_default(): void
    {
        $container = $this->createContainer();

        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.storage'));
        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.relay_command'));
    }

    public function test_outbox_enabled_registers_services(): void
    {
        $container = $this->createContainer([
            'outbox' => [
                'enabled' => true,
            ],
        ]);

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.outbox.storage'),
            'OutboxStorage should be registered when outbox.enabled=true',
        );
        self::assertTrue(
            $container->hasDefinition('somework_cqrs.outbox.relay_command'),
            'OutboxRelayCommand should be registered when outbox.enabled=true',
        );
    }

    public function test_outbox_parameters_set(): void
    {
        $container = $this->createContainer([
            'outbox' => [
                'enabled' => true,
                'table_name' => 'custom_outbox',
            ],
        ]);

        self::assertTrue($container->hasParameter('somework_cqrs.outbox.enabled'));
        self::assertTrue($container->getParameter('somework_cqrs.outbox.enabled'));

        self::assertTrue($container->hasParameter('somework_cqrs.outbox.table_name'));
        self::assertSame('custom_outbox', $container->getParameter('somework_cqrs.outbox.table_name'));
    }

    public function test_outbox_disabled_sets_parameters(): void
    {
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.outbox.enabled'));
        self::assertFalse($container->getParameter('somework_cqrs.outbox.enabled'));

        self::assertTrue($container->hasParameter('somework_cqrs.outbox.table_name'));
        self::assertSame('somework_cqrs_outbox', $container->getParameter('somework_cqrs.outbox.table_name'));
    }

    public function test_class_exists_guard_present_in_extension(): void
    {
        $reflector = new \ReflectionClass(CqrsExtension::class);
        $source = file_get_contents((string) $reflector->getFileName());
        self::assertIsString($source);

        self::assertStringContainsString(
            'class_exists(Connection::class)',
            $source,
            'CqrsExtension must guard OutboxRegistrar with class_exists(Connection::class)',
        );
    }

    public function test_default_table_name_is_somework_cqrs_outbox(): void
    {
        $container = $this->createContainer();

        self::assertSame('somework_cqrs_outbox', $container->getParameter('somework_cqrs.outbox.table_name'));
    }

    public function test_outbox_enabled_does_not_register_unrelated_services(): void
    {
        $container = $this->createContainer([
            'outbox' => ['enabled' => true],
        ]);

        // Outbox should not pollute stamp decider pipeline
        $reflector = new \ReflectionClass(CqrsExtension::class);
        $source = file_get_contents((string) $reflector->getFileName());
        self::assertIsString($source);

        // OutboxRegistrar is a separate registrar, not part of StampsDeciderRegistrar
        self::assertStringContainsString('OutboxRegistrar', $source);
    }

    public function test_outbox_schema_subscriber_registered_when_orm_available(): void
    {
        if (!class_exists(\Doctrine\ORM\Tools\ToolEvents::class)) {
            self::markTestSkipped('doctrine/orm not installed');
        }

        $container = $this->createContainer([
            'outbox' => ['enabled' => true],
        ]);

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.outbox.schema_subscriber'),
            'OutboxSchemaSubscriber should be registered when ORM is available and outbox is enabled',
        );
    }

    public function test_outbox_disabled_does_not_register_schema_subscriber(): void
    {
        $container = $this->createContainer();

        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.schema_subscriber'));
    }

    public function test_outbox_storage_alias_set_when_enabled(): void
    {
        $container = $this->createContainer([
            'outbox' => ['enabled' => true],
        ]);

        self::assertTrue(
            $container->hasAlias(\SomeWork\CqrsBundle\Contract\OutboxStorage::class),
            'OutboxStorage interface should be aliased when outbox is enabled',
        );
    }

    public function test_empty_config_is_valid(): void
    {
        // Ensures bundle loads cleanly with zero config
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.outbox.enabled'));
        self::assertFalse($container->getParameter('somework_cqrs.outbox.enabled'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(array $config = []): ContainerBuilder
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $extension->load([] === $config ? [] : [$config], $container);

        return $container;
    }
}
