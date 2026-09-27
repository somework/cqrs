<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\MessengerMiddlewareInjector;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OutboxStoreMiddlewarePass;
use SomeWork\CqrsBundle\Messenger\OutboxBypassMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxPrepareMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;

#[CoversClass(OutboxStoreMiddlewarePass::class)]
#[CoversClass(MessengerMiddlewareInjector::class)]
final class OutboxStoreMiddlewarePassTest extends TestCase
{
    private const BYPASS = OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.messenger.bus.default.middleware.doctrine_transaction';

    public function test_inserts_the_middleware_after_the_default_stamps_and_before_handling_on_every_cqrs_bus(): void
    {
        $container = $this->createContainer();

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertSame(OutboxStoreMiddleware::class, $container->getDefinition(OutboxStoreMiddlewarePass::MIDDLEWARE_ID)->getClass());
        self::assertSame(OutboxPrepareMiddleware::class, $container->getDefinition(OutboxStoreMiddlewarePass::PREPARE_MIDDLEWARE_ID)->getClass());
        self::assertSame(
            ['messenger.bus.default.middleware.add_default_stamps_middleware', OutboxStoreMiddlewarePass::PREPARE_MIDDLEWARE_ID, 'messenger.bus.default.middleware.add_bus_name_stamp_middleware', self::BYPASS, 'app.validation', OutboxStoreMiddlewarePass::MIDDLEWARE_ID, 'messenger.bus.default.middleware.send_message', 'messenger.bus.default.middleware.handle_message'],
            $this->middlewareIds($container, 'messenger.bus.default'),
        );
        // Doctrine's transaction middleware (listed before the application's here) is skipped by stored messages.
        $bypass = $container->getDefinition(self::BYPASS);
        self::assertSame(OutboxBypassMiddleware::class, $bypass->getClass());
        self::assertSame('messenger.bus.default.middleware.doctrine_transaction', (string) $bypass->getArgument(0));
        // Listed twice (e.g. one per entity manager), the second gets a hash suffix; not the logger of another bundle.
        self::assertSame(
            [OutboxStoreMiddlewarePass::PREPARE_MIDDLEWARE_ID, OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.messenger.middleware.doctrine_open_transaction_logger', 'app.validation', OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.event.async_bus.middleware.doctrine_transaction.kaQ27bZ', OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.event.async_bus.middleware.doctrine_transaction.xrFKpV.', 'app.my_doctrine_transaction_audit', 'app.transactions', OutboxStoreMiddlewarePass::MIDDLEWARE_ID],
            $this->middlewareIds($container, 'event.async_bus'),
        );
    }

    public function test_gives_the_writer_the_messenger_transport_names(): void
    {
        $container = $this->createContainer();

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertSame(['async', 'orders'], $container->getDefinition('somework_cqrs.outbox.writer')->getArgument('$transportNames'));
    }

    public function test_leaves_the_transports_unchecked_without_messenger_transports(): void
    {
        $container = $this->createContainer();
        $container->removeDefinition('messenger.transport.orders');
        $container->removeDefinition('messenger.transport.async');

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertNull($container->getDefinition('somework_cqrs.outbox.writer')->getArgument('$transportNames'));
    }

    public function test_does_nothing_when_the_outbox_is_disabled(): void
    {
        $container = $this->createContainer();
        $container->removeDefinition('somework_cqrs.outbox.writer');

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertFalse($container->hasDefinition(OutboxStoreMiddlewarePass::MIDDLEWARE_ID));
        self::assertNotContains(OutboxStoreMiddlewarePass::MIDDLEWARE_ID, $this->middlewareIds($container, 'messenger.bus.default'));
    }

    public function test_the_dbal_middleware_of_doctrine_bridge_8_2_is_skipped_too(): void
    {
        $container = $this->createContainer();
        $container->setParameter('somework_cqrs.bus.command', 'command.bus');
        $container->setDefinition('command.bus', (new Definition())->setArgument(0, new IteratorArgument([
            new Reference('messenger.middleware.doctrine_dbal_open_transaction_logger'),
            new Reference('command.bus.middleware.doctrine_dbal_transaction'),
            // Listed twice: the parent of the one with a hash suffix is not visible.
            new Reference('command.bus.middleware.doctrine_dbal_transaction.kaQ27bZ'),
            new Reference('app.dbal_transaction_audit'),
            new Reference('command.bus.middleware.send_message'),
        ])));
        $container->setDefinition('command.bus.middleware.doctrine_dbal_transaction', new ChildDefinition('messenger.middleware.doctrine_dbal_transaction'));

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertSame(
            [
                OutboxStoreMiddlewarePass::PREPARE_MIDDLEWARE_ID,
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.messenger.middleware.doctrine_dbal_open_transaction_logger',
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.command.bus.middleware.doctrine_dbal_transaction',
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.command.bus.middleware.doctrine_dbal_transaction.kaQ27bZ',
                'app.dbal_transaction_audit',
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID,
                'command.bus.middleware.send_message',
            ],
            $this->middlewareIds($container, 'command.bus'),
        );
    }

    public function test_the_dbal_middleware_of_doctrine_bridge_8_2_is_found_by_its_class(): void
    {
        // It has no configuration shortcut: Symfony's documentation registers it under its class name.
        $transaction = 'Symfony\\Bridge\\Doctrine\\Messenger\\DoctrineDbalTransactionMiddleware';
        $logger = 'Symfony\\Bridge\\Doctrine\\Messenger\\DoctrineDbalOpenTransactionLoggerMiddleware';
        $container = $this->createContainer();
        $container->setParameter('somework_cqrs.bus.command', 'command.bus');
        $container->register($logger, $logger);
        // Any other id, even behind an alias.
        $container->register('app.dbal_transaction', $transaction);
        $container->setAlias('app.transaction', 'app.dbal_transaction');
        // Registered as a middleware factory: Messenger makes a child definition of it per bus.
        $container->register($transaction)->setAbstract(true);
        $container->setDefinition('command.bus.middleware.'.$transaction, new ChildDefinition($transaction));
        $container->register('app.dbal_audit', 'App\\Middleware\\DbalAuditMiddleware');
        $container->setDefinition('command.bus', (new Definition())->setArgument(0, new IteratorArgument([
            new Reference($logger),
            new Reference('app.transaction'),
            new Reference('command.bus.middleware.'.$transaction),
            new Reference('app.dbal_audit'),
            new Reference('command.bus.middleware.send_message'),
        ])));

        (new OutboxStoreMiddlewarePass())->process($container);

        self::assertSame(
            [
                OutboxStoreMiddlewarePass::PREPARE_MIDDLEWARE_ID,
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.'.$logger,
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.app.transaction',
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.command.bus.middleware.'.$transaction,
                'app.dbal_audit',
                OutboxStoreMiddlewarePass::MIDDLEWARE_ID,
                'command.bus.middleware.send_message',
            ],
            $this->middlewareIds($container, 'command.bus'),
        );
        self::assertSame('app.transaction', (string) $container->getDefinition(OutboxStoreMiddlewarePass::MIDDLEWARE_ID.'.bypass.app.transaction')->getArgument(0));
    }

    public function test_fails_before_messenger_pass_built_the_buses(): void
    {
        $container = $this->createContainer();
        // Symfony 8.2 registers the bus like this, and MessengerPass (priority -16) builds its middleware list.
        $container->register('command.bus')->addArgument([])->addTag('messenger.bus');
        $container->setParameter('command.bus.middleware', [['id' => 'send_message'], ['id' => 'handle_message']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Messenger bus "command.bus" still has its "command.bus.middleware" parameter: Symfony\'s MessengerPass has not built the buses yet.');

        (new OutboxStoreMiddlewarePass())->process($container);
    }

    public function test_fails_when_a_cqrs_bus_has_no_middleware_list(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('event.async_bus', new Definition(\stdClass::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The outbox needs its middleware on the Messenger bus "event.async_bus"');

        (new OutboxStoreMiddlewarePass())->process($container);
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.default');
        $container->setParameter('somework_cqrs.bus.event_async', 'event.async_bus');
        $container->register('somework_cqrs.outbox.writer');
        $container->register('messenger.transport.orders')->addTag('messenger.receiver', ['alias' => 'orders']);
        $container->register('messenger.transport.async')->addTag('messenger.receiver', ['alias' => 'async']);
        $container->setDefinition('messenger.bus.default', (new Definition())->setArgument(0, new IteratorArgument([
            new Reference('messenger.bus.default.middleware.add_default_stamps_middleware'),
            new Reference('messenger.bus.default.middleware.add_bus_name_stamp_middleware'),
            new Reference('messenger.bus.default.middleware.doctrine_transaction'),
            new Reference('app.validation'),
            new Reference('messenger.bus.default.middleware.send_message'),
            new Reference('messenger.bus.default.middleware.handle_message'),
        ])));
        // A bus without Messenger's default middleware: the outbox middleware goes first and last.
        $container->setDefinition('event.async_bus', (new Definition())->setArgument(0, new IteratorArgument([
            new Reference('messenger.middleware.doctrine_open_transaction_logger'),
            new Reference('app.validation'),
            new Reference('event.async_bus.middleware.doctrine_transaction.kaQ27bZ'),
            // ContainerBuilder::hash() maps "/" to ".": e.g. the entity manager "reporting".
            new Reference('event.async_bus.middleware.doctrine_transaction.xrFKpV.'),
            new Reference('app.my_doctrine_transaction_audit'),
            // A child of another middleware, whatever its id.
            new Reference('app.transactions'),
        ])));
        $container->setDefinition('event.async_bus.middleware.doctrine_transaction.xrFKpV.', new ChildDefinition('messenger.middleware.doctrine_transaction'));
        $container->setDefinition('app.transactions', new ChildDefinition('app.other_middleware'));

        return $container;
    }

    /**
     * @return list<string>
     */
    private function middlewareIds(ContainerBuilder $container, string $busId): array
    {
        $argument = $container->getDefinition($busId)->getArgument(0);
        self::assertInstanceOf(IteratorArgument::class, $argument);

        return array_values(array_map(static fn (mixed $reference): string => (string) $reference, $argument->getValues()));
    }
}
