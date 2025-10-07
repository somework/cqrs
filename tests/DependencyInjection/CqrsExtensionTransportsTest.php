<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ImportLegacyDataCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class CqrsExtensionTransportsTest extends TestCase
{
    public function test_message_transport_decider_receives_configured_transports(): void
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.bus.command_async', \stdClass::class)->setPublic(true);
        $container->register('messenger.bus.event_async', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);
        $container->register('messenger.bus.command_async.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);
        $container->register('messenger.bus.event_async.messenger.handlers_locator', ServiceLocator::class)->setArguments([[]])->setPublic(true);

        $extension->load([
            [
                'buses' => [
                    'command_async' => 'messenger.bus.command_async',
                    'event_async' => 'messenger.bus.event_async',
                ],
                'transports' => [
                    'command' => [
                        'default' => ['command-default'],
                        'map' => [
                            CreateTaskCommand::class => ['command-override'],
                        ],
                    ],
                    'command_async' => [
                        'default' => ['command-async-default'],
                        'map' => [
                            CreateTaskCommand::class => ['command-async-override'],
                        ],
                    ],
                    'query' => [
                        'default' => ['query-default'],
                        'map' => [
                            FindTaskQuery::class => ['query-override'],
                        ],
                    ],
                    'event' => [
                        'default' => ['event-default'],
                        'map' => [
                            TaskCreatedEvent::class => ['event-override'],
                        ],
                    ],
                    'event_async' => [
                        'default' => ['event-async-default'],
                        'map' => [
                            TaskCreatedEvent::class => ['event-async-override'],
                        ],
                    ],
                ],
            ],
        ], $container);

        $container->getDefinition('somework_cqrs.stamp_decider.message_transport')->setPublic(true);
        $container->compile();

        $decider = $container->get('somework_cqrs.stamp_decider.message_transport');
        self::assertInstanceOf(MessageTransportStampDecider::class, $decider);

        $overrideCommand = new CreateTaskCommand('id', 'name');
        $this->assertTransportNames(
            ['command-override'],
            $decider->decide($overrideCommand, DispatchMode::SYNC, []),
        );

        $defaultCommand = new GenerateReportCommand('report');
        $this->assertTransportNames(
            ['command-default'],
            $decider->decide($defaultCommand, DispatchMode::SYNC, []),
        );

        $asyncDefaultCommand = new ImportLegacyDataCommand('legacy');
        $this->assertTransportNames(
            ['command-async-default'],
            $decider->decide($asyncDefaultCommand, DispatchMode::ASYNC, []),
        );

        $this->assertTransportNames(
            ['command-async-override'],
            $decider->decide($overrideCommand, DispatchMode::ASYNC, []),
        );

        $overrideQuery = new FindTaskQuery('123');
        $this->assertTransportNames(
            ['query-override'],
            $decider->decide($overrideQuery, DispatchMode::SYNC, []),
        );

        $defaultQuery = new class implements \SomeWork\CqrsBundle\Contract\Query {
        };
        $this->assertTransportNames(
            ['query-default'],
            $decider->decide($defaultQuery, DispatchMode::SYNC, []),
        );

        $overrideEvent = new TaskCreatedEvent('task');
        $this->assertTransportNames(
            ['event-override'],
            $decider->decide($overrideEvent, DispatchMode::SYNC, []),
        );

        $defaultEvent = new OrderPlacedEvent('order');
        $this->assertTransportNames(
            ['event-default'],
            $decider->decide($defaultEvent, DispatchMode::SYNC, []),
        );

        $this->assertTransportNames(
            ['event-async-override'],
            $decider->decide($overrideEvent, DispatchMode::ASYNC, []),
        );

        $this->assertTransportNames(
            ['event-async-default'],
            $decider->decide($defaultEvent, DispatchMode::ASYNC, []),
        );
    }

    /**
     * @param list<string>         $expected
     * @param list<StampInterface> $stamps
     */
    private function assertTransportNames(array $expected, array $stamps): void
    {
        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame($expected, $stamps[0]->getTransportNames());
    }
}
