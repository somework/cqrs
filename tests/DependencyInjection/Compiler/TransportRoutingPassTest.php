<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\TransportRoutingPass;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

#[CoversClass(TransportRoutingPass::class)]
final class TransportRoutingPassTest extends TestCase
{
    public function test_passes_the_routed_message_types_to_the_transport_decider(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransportRoutingPass::DECIDER_ID, MessageTransportStampDecider::class);
        // What FrameworkBundle registers for framework.messenger.routing.
        $container->register('messenger.senders_locator', SendersLocator::class)->setArguments([
            [AsyncTaskCommand::class => ['jobs'], RetryAwareMessage::class => ['retrying'], '*' => ['all']],
            null,
        ]);

        (new TransportRoutingPass())->process($container);

        self::assertSame(
            [AsyncTaskCommand::class, RetryAwareMessage::class, '*'],
            $container->getDefinition(TransportRoutingPass::DECIDER_ID)->getArgument('$routedMessageTypes'),
        );
    }

    public function test_fails_before_messenger_pass_added_the_routes_of_the_handlers(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransportRoutingPass::DECIDER_ID, MessageTransportStampDecider::class);
        $container->register('messenger.senders_locator', SendersLocator::class)->setArguments([[], null]);
        // Symfony 8.2 registers the bus like this; MessengerPass (priority -16) builds it and adds
        // the routes of #[AsMessageHandler(transport: ...)] to the senders locator.
        $container->register('messenger.bus.default')->addArgument([])->addTag('messenger.bus');
        $container->setParameter('messenger.bus.default.middleware', [['id' => 'send_message'], ['id' => 'handle_message']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Symfony\'s MessengerPass has not built the buses yet');

        (new TransportRoutingPass())->process($container);
    }

    public function test_does_nothing_without_messenger_routing(): void
    {
        $container = new ContainerBuilder();
        $decider = $container->register(TransportRoutingPass::DECIDER_ID, MessageTransportStampDecider::class);

        (new TransportRoutingPass())->process($container);

        self::assertSame([], $decider->getArguments());
    }
}
