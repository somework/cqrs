<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoragePass;
use SomeWork\CqrsBundle\DependencyInjection\Registration\OutboxRegistrar;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\DecoratingOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
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

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('doctrine.dbal.default_connection', Connection::class)
            ->setFactory([DriverManager::class, 'getConnection'])
            ->setArguments([['driver' => 'pdo_sqlite', 'memory' => true]]);
        $container->register('messenger.default_serializer', PhpSerializer::class);
        $container->register('messenger.default_bus', MessageBus::class);
        (new OutboxRegistrar())->register($container, ['enabled' => true, 'table_name' => 'outbox']);
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
