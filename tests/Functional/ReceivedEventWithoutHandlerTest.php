<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LogLevel;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\SyncPinnedEventTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * A worker that receives an event on a bus without its handlers acknowledges it, and warns when
 * the event has handlers elsewhere: the container gives the middleware the events with handlers.
 */
#[CoversNothing]
final class ReceivedEventWithoutHandlerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return SyncPinnedEventTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    public function test_a_received_event_whose_handlers_are_on_another_bus_is_acknowledged_with_a_warning(): void
    {
        $envelope = $this->asyncEventBus()->dispatch(new Envelope(new TaskCreatedEvent('task-1'), [new ReceivedStamp('async')]));

        self::assertInstanceOf(TaskCreatedEvent::class, $envelope->getMessage());
        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('has no handler on the bus that consumes it', $warnings[0]['message']);
        self::assertSame(['message' => TaskCreatedEvent::class, 'transport' => 'async'], $warnings[0]['context']);
    }

    public function test_a_received_event_without_any_handler_is_acknowledged_silently(): void
    {
        $this->asyncEventBus()->dispatch(new Envelope(new OrderPlacedEvent('order-1'), [new ReceivedStamp('async')]));

        self::assertSame([], $this->warnings());
    }

    private function asyncEventBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.bus.events_async');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private function warnings(): array
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return array_values(array_filter($logger->records, static fn (array $record): bool => LogLevel::WARNING === $record['level']));
    }
}
