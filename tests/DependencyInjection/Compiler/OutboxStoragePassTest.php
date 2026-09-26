<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Outbox\FailedOutboxMessages;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoragePass;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CapableOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\DecoratingOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(OutboxStoragePass::class)]
#[CoversClass(OutboxRegistrar::class)]
final class OutboxStoragePassTest extends TestCase
{
    public function test_a_decorated_storage_keeps_the_table_commands_on_the_dbal_storage(): void
    {
        // e.g. #[AsDecorator('somework_cqrs.outbox.storage')] to add logging to the storage.
        $container = $this->container();
        $container->register('app.logging_outbox', DecoratingOutboxStorage::class)
            ->setDecoratedService('somework_cqrs.outbox.storage')
            ->setArguments([new Reference('.inner')])
            ->setPublic(true);
        $container->compile();

        foreach (['somework_cqrs.outbox.setup_command', 'somework_cqrs.outbox.failed_command', 'somework_cqrs.outbox.health_checker'] as $id) {
            self::assertInstanceOf(DbalOutboxStorage::class, self::argument($container->get($id), 'outboxStorage'), $id);
        }
        $relay = $container->get('somework_cqrs.outbox.relay_command');
        self::assertInstanceOf(DecoratingOutboxStorage::class, self::argument($relay, 'outboxStorage'), 'The relay uses the decorated storage.');
        self::assertInstanceOf(DbalOutboxStorage::class, self::argument($relay, 'table'));
        $relayLoop = self::argument($relay, 'relay');
        self::assertIsObject($relayLoop);
        self::assertInstanceOf(DbalOutboxStorage::class, self::argument($relayLoop, 'unitOfWork'), 'Dispatches run in units of work of the DBAL storage.');
        self::assertInstanceOf(DecoratingOutboxStorage::class, $container->get('app.logging_outbox'));
    }

    public function test_a_replaced_storage_is_used_everywhere(): void
    {
        $container = $this->container();
        $container->setDefinition('somework_cqrs.outbox.storage', (new Definition(InMemoryOutboxStorage::class))->setPublic(true));
        $container->compile();

        self::assertInstanceOf(InMemoryOutboxStorage::class, self::argument($container->get('somework_cqrs.outbox.setup_command'), 'outboxStorage'));
        self::assertNull(self::argument($container->get('somework_cqrs.outbox.relay_command'), 'table'));
    }

    public function test_a_decorated_custom_storage_keeps_the_capabilities_on_the_custom_storage(): void
    {
        $container = $this->container(['storage' => 'app.outbox']);
        $container->register('app.outbox', CapableOutboxStorage::class);
        $container->register('app.logging_outbox', DecoratingOutboxStorage::class)
            ->setDecoratedService('somework_cqrs.outbox.storage')
            ->setArguments([new Reference('.inner')]);
        $container->compile();

        foreach (['somework_cqrs.outbox.setup_command', 'somework_cqrs.outbox.failed_command', 'somework_cqrs.outbox.health_checker'] as $id) {
            self::assertInstanceOf(CapableOutboxStorage::class, self::argument($container->get($id), 'outboxStorage'), $id);
        }
        $relay = $container->get('somework_cqrs.outbox.relay_command');
        self::assertInstanceOf(DecoratingOutboxStorage::class, self::argument($relay, 'outboxStorage'));
        self::assertInstanceOf(CapableOutboxStorage::class, self::argument($relay, 'table'));
        self::assertFalse($container->has('somework_cqrs.outbox.dbal_storage'));
    }

    public function test_a_custom_storage_without_a_schema_does_not_break_the_relay(): void
    {
        // The relay's schema report needs OutboxSchema; a storage without it is not passed (was a TypeError).
        $container = $this->container(['storage' => 'app.outbox']);
        $container->register('app.outbox', InMemoryOutboxStorage::class);
        $container->compile();

        $relay = $container->get('somework_cqrs.outbox.relay_command');
        self::assertInstanceOf(InMemoryOutboxStorage::class, self::argument($relay, 'outboxStorage'));
        self::assertNull(self::argument($relay, 'table'));
        $relayLoop = self::argument($relay, 'relay');
        self::assertIsObject($relayLoop);
        self::assertNull(self::argument($relayLoop, 'unitOfWork'));
        self::assertInstanceOf(InMemoryOutboxStorage::class, self::argument($container->get('somework_cqrs.outbox.setup_command'), 'outboxStorage'));
    }

    public function test_the_writer_checks_transactions_on_the_storage_behind_the_decorators(): void
    {
        $container = $this->container(['require_transaction' => true]);
        $container->getDefinition('somework_cqrs.outbox.writer')->setPublic(true);
        $container->compile();

        $writer = $container->get('somework_cqrs.outbox.writer');
        self::assertInstanceOf(DbalOutboxStorage::class, self::argument($writer, 'transaction'));
        self::assertTrue(self::argument($writer, 'requireTransaction'));

        // A storage that cannot tell whether a transaction is open is not checked.
        $custom = $this->container(['storage' => 'app.outbox']);
        $custom->register('app.outbox', InMemoryOutboxStorage::class);
        $custom->getDefinition('somework_cqrs.outbox.writer')->setPublic(true);
        $custom->compile();
        self::assertNull(self::argument($custom->get('somework_cqrs.outbox.writer'), 'transaction'));
    }

    public function test_capabilities_autowire_to_the_storage_that_implements_them(): void
    {
        $container = $this->container(['storage' => 'app.outbox']);
        $container->register('app.outbox', InMemoryOutboxStorage::class);
        (new OutboxStoragePass())->process($container);

        self::assertFalse($container->hasAlias(FailedOutboxMessages::class), 'The in-memory storage lists no failed messages.');

        $dbal = $this->container();
        (new OutboxStoragePass())->process($dbal);
        self::assertSame('somework_cqrs.outbox.base_storage', (string) $dbal->getAlias(FailedOutboxMessages::class));
    }

    public function test_a_custom_storage_must_be_an_outbox_storage(): void
    {
        $container = $this->container(['storage' => 'app.outbox']);
        $container->register('app.outbox', \stdClass::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The outbox storage "app.outbox" configured at "somework_cqrs.outbox.storage" must implement SomeWork\CqrsBundle\Contract\Outbox\OutboxStorage, stdClass does not.');

        $container->compile();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.dbal.default_connection', Connection::class)
            ->setFactory([DriverManager::class, 'getConnection'])
            ->setArguments([['driver' => 'pdo_sqlite', 'memory' => true]]);
        $container->register('messenger.default_serializer', PhpSerializer::class);
        $container->register('messenger.default_bus', MessageBus::class);
        (new OutboxRegistrar())->register($container, ['enabled' => true, 'table_name' => 'outbox'] + $config);
        $container->addCompilerPass(new OutboxStoragePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        foreach (['somework_cqrs.outbox.setup_command', 'somework_cqrs.outbox.failed_command', 'somework_cqrs.outbox.health_checker', 'somework_cqrs.outbox.relay_command'] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        return $container;
    }

    private static function argument(object $service, string $property): mixed
    {
        return (new \ReflectionProperty($service, $property))->getValue($service);
    }
}
