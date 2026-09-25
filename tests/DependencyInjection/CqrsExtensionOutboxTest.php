<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\ToolEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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

        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.dbal_storage'));
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
            $container->hasDefinition('somework_cqrs.outbox.dbal_storage'),
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

    public function test_enabling_outbox_without_dbal_fails_with_a_clear_message(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('doctrine/dbal is not installed');

        $container = new ContainerBuilder();
        (new CqrsExtension(static fn (string $class): bool => Connection::class !== $class && class_exists($class)))
            ->load([['outbox' => ['enabled' => true]]], $container);
    }

    public function test_a_custom_storage_does_not_need_dbal(): void
    {
        $container = new ContainerBuilder();
        (new CqrsExtension(static fn (string $class): bool => Connection::class !== $class && class_exists($class)))
            ->load([['outbox' => ['enabled' => true, 'storage' => InMemoryOutboxStorage::class]]], $container);

        self::assertSame(InMemoryOutboxStorage::class, (string) $container->getAlias('somework_cqrs.outbox.storage'));
        self::assertTrue($container->hasDefinition(InMemoryOutboxStorage::class), 'A class name is registered as a service.');
    }

    public function test_schema_subscriber_is_skipped_without_doctrine_orm(): void
    {
        $container = new ContainerBuilder();
        (new CqrsExtension(static fn (string $class): bool => ToolEvents::class !== $class && class_exists($class)))
            ->load([['outbox' => ['enabled' => true]]], $container);

        self::assertTrue($container->hasDefinition('somework_cqrs.outbox.dbal_storage'));
        self::assertFalse($container->hasDefinition('somework_cqrs.outbox.schema_subscriber'));
    }

    public function test_default_table_name_is_somework_cqrs_outbox(): void
    {
        $container = $this->createContainer();

        self::assertSame('somework_cqrs_outbox', $container->getParameter('somework_cqrs.outbox.table_name'));
    }

    public function test_outbox_enabled_does_not_register_stamp_deciders(): void
    {
        $withOutbox = $this->createContainer(['outbox' => ['enabled' => true]]);
        $withoutOutbox = $this->createContainer();

        self::assertSame(
            array_keys($withoutOutbox->findTaggedServiceIds('somework_cqrs.dispatch_stamp_decider')),
            array_keys($withOutbox->findTaggedServiceIds('somework_cqrs.dispatch_stamp_decider')),
        );
    }

    public function test_outbox_schema_subscriber_registered_when_orm_available(): void
    {
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

    public function test_max_attempts_defaults_to_ten_and_reaches_the_relay(): void
    {
        self::assertSame(10, $this->createContainer(['outbox' => ['enabled' => true]])->getDefinition('somework_cqrs.outbox.relay_command')->getArgument('$maxAttempts'));
        self::assertSame(3, $this->createContainer(['outbox' => ['enabled' => true, 'max_attempts' => 3]])->getDefinition('somework_cqrs.outbox.relay_command')->getArgument('$maxAttempts'));
    }

    public function test_max_attempts_below_one_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"somework_cqrs.outbox.max_attempts" must be at least 1, 0 given.');

        $this->createContainer(['outbox' => ['enabled' => true, 'max_attempts' => 0]]);
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
