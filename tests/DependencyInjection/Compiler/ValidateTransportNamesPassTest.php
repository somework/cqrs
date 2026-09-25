<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TransportBoundHandlers;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsynchronousQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\SendNotificationCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBus;

use function array_map;
use function sprintf;

#[CoversClass(ValidateTransportNamesPass::class)]
final class ValidateTransportNamesPassTest extends TestCase
{
    public function test_it_throws_when_transport_is_missing(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['missing'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Messenger transport "missing" configured for SomeWork CQRS is not defined.');

        $container->compile();
    }

    public function test_an_event_handler_bound_to_an_unknown_transport_fails(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransportBoundHandlers::class, TransportBoundHandlers::class)->addTag('messenger.message_handler', ['from_transport' => 'audit']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('#[AsEventHandler(fromTransport: "audit")] on "'.TransportBoundHandlers::class.'" names a Messenger transport that is not defined.');

        (new ValidateTransportNamesPass())->process($container);
    }

    public function test_an_event_handler_bound_to_a_known_transport_passes(): void
    {
        $container = new ContainerBuilder();
        $container->register('messenger.transport.audit', \stdClass::class);
        $container->register(TransportBoundHandlers::class, TransportBoundHandlers::class)->addTag('messenger.message_handler', ['from_transport' => 'audit']);

        (new ValidateTransportNamesPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    public function test_it_allows_known_transports(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $container->register('messenger.transport.known', \stdClass::class);

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['known'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $container->compile();

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_an_asynchronous_attribute_on_a_query(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', ['query' => [['message' => AsynchronousQuery::class]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"%s" is a query and carries #[Asynchronous]: queries are always handled synchronously.', AsynchronousQuery::class));

        (new ValidateTransportNamesPass())->process($container);
    }

    public function test_it_rejects_an_unknown_transport_of_an_asynchronous_attribute(): void
    {
        $container = $this->asyncContainer([SendNotificationCommand::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('#[Asynchronous(transport: "notifications")] on "%s" names a Messenger transport that is not defined.', SendNotificationCommand::class));

        (new ValidateTransportNamesPass())->process($container);
    }

    public function test_it_accepts_a_known_transport_of_an_asynchronous_attribute(): void
    {
        $container = $this->asyncContainer([SendNotificationCommand::class, AsyncTaskCommand::class]);
        $container->register('messenger.transport.notifications', \stdClass::class);
        $container->register('messenger.transport.async', \stdClass::class);

        (new ValidateTransportNamesPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    public function test_an_asynchronous_message_needs_an_async_bus(): void
    {
        $container = $this->asyncContainer([AsyncTaskCommand::class], asyncBus: null);
        $container->register('messenger.transport.async', \stdClass::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"%s" carries #[Asynchronous], but "somework_cqrs.buses.command_async" is not configured', AsyncTaskCommand::class));

        (new ValidateTransportNamesPass())->process($container);
    }

    public function test_a_bare_asynchronous_attribute_needs_a_transport(): void
    {
        $container = $this->asyncContainer([AsyncTaskCommand::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"%s" carries #[Asynchronous] without a transport, but there is no "async" transport, no "somework_cqrs.transports.command_async" entry and no framework.messenger.routing route for it.', AsyncTaskCommand::class));

        (new ValidateTransportNamesPass())->process($container);
    }

    /**
     * @return iterable<string, array{\Closure(ContainerBuilder): mixed}>
     */
    public static function waysToGiveABareAttributeATransport(): iterable
    {
        yield 'section default' => [static fn (ContainerBuilder $container) => $container->setParameter('somework_cqrs.transport_mapping', ['command_async' => ['default' => ['jobs'], 'map' => []]])];
        yield 'map entry for an interface' => [static fn (ContainerBuilder $container) => $container->setParameter('somework_cqrs.transport_mapping', ['command_async' => ['default' => [], 'map' => [Command::class => ['jobs']]]])];
        yield 'namespace wildcard route' => [static fn (ContainerBuilder $container) => $container->register('messenger.senders_locator', \stdClass::class)->setArguments([['SomeWork\\CqrsBundle\\Tests\\*' => ['jobs']]])];
        yield 'catch-all route' => [static fn (ContainerBuilder $container) => $container->register('messenger.senders_locator', \stdClass::class)->setArguments([['*' => ['jobs']]])];
        yield 'exact sync dispatch mode' => [static fn (ContainerBuilder $container) => $container->register('somework_cqrs.dispatch_mode_decider', \stdClass::class)->setArgument('$commandMap', [AsyncTaskCommand::class => DispatchMode::SYNC])];
    }

    /**
     * @param \Closure(ContainerBuilder): mixed $configure
     */
    #[DataProvider('waysToGiveABareAttributeATransport')]
    public function test_a_bare_asynchronous_attribute_is_satisfied_by(\Closure $configure): void
    {
        $container = $this->asyncContainer([AsyncTaskCommand::class]);
        $configure($container);

        (new ValidateTransportNamesPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param list<class-string> $messages
     */
    private function asyncContainer(array $messages, ?string $asyncBus = 'command.async_bus'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', ['command' => array_map(static fn (string $message): array => ['message' => $message], $messages)]);
        $container->setParameter('somework_cqrs.bus.command_async', $asyncBus);

        return $container;
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', MessageBus::class)->addTag('messenger.bus')->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $bundle = new SomeWorkCqrsBundle();
        $bundle->build($container);

        return $container;
    }
}
