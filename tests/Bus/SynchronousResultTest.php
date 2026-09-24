<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\SynchronousResult;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

#[CoversClass(SynchronousResult::class)]
#[CoversClass(MessageSentToTransportException::class)]
#[CoversClass(DuplicateMessageException::class)]
final class SynchronousResultTest extends TestCase
{
    public function test_returns_handled_stamps(): void
    {
        $handled = new HandledStamp('result', 'Handler::__invoke');

        self::assertSame([$handled], SynchronousResult::handledStamps(new Envelope(new CreateTaskCommand('1', 'x'), [$handled]), 'command'));
    }

    public function test_message_sent_to_a_transport_is_reported(): void
    {
        $envelope = new Envelope(new CreateTaskCommand('1', 'x'), [new SentStamp('Sender', 'async')]);

        try {
            SynchronousResult::handledStamps($envelope, 'command');
            self::fail('Expected MessageSentToTransportException.');
        } catch (MessageSentToTransportException $exception) {
            self::assertSame(CreateTaskCommand::class, $exception->messageFqcn);
            self::assertSame(['async'], $exception->transportNames);
            self::assertStringContainsString('instead of being handled synchronously', $exception->getMessage());
        }
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_deduplicated_message_is_reported(): void
    {
        $envelope = new Envelope(new CreateTaskCommand('1', 'x'), [new DeduplicateStamp('task-1')]);

        $this->expectException(DuplicateMessageException::class);
        $this->expectExceptionMessage('deduplication key "task-1"');

        SynchronousResult::handledStamps($envelope, 'command');
    }

    public function test_missing_handler_is_reported(): void
    {
        $this->expectException(NoHandlerException::class);

        SynchronousResult::handledStamps(new Envelope(new CreateTaskCommand('1', 'x')), 'command');
    }

    public function test_deferral_stamps_are_removed(): void
    {
        $delay = new DelayStamp(10);

        self::assertSame([$delay], SynchronousResult::withoutDeferral([new DispatchAfterCurrentBusStamp(), $delay]));
    }

    public function test_single_handler_failure_is_unwrapped(): void
    {
        $domainException = new \DomainException('boom');
        $failure = new HandlerFailedException(new Envelope(new \stdClass()), ['Handler::__invoke' => $domainException]);

        self::assertSame($domainException, SynchronousResult::unwrap($failure));
    }

    public function test_multiple_handler_failures_stay_wrapped(): void
    {
        $failure = new HandlerFailedException(new Envelope(new \stdClass()), [
            'A::__invoke' => new \DomainException('a'),
            'B::__invoke' => new \DomainException('b'),
        ]);

        self::assertSame($failure, SynchronousResult::unwrap($failure));
    }
}
