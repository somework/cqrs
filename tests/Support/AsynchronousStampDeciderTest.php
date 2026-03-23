<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\AsynchronousStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CustomTransportCommand;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[CoversClass(AsynchronousStampDecider::class)]
final class AsynchronousStampDeciderTest extends TestCase
{
    public function test_adds_transport_stamp_for_async_attributed_message(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new AsyncTaskCommand('1');

        $stamps = $decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['async'], $stamps[0]->getTransportNames());
    }

    public function test_uses_custom_transport_name(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new CustomTransportCommand('1');

        $stamps = $decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['my_queue'], $stamps[0]->getTransportNames());
    }

    public function test_no_stamp_added_for_message_without_attribute(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new CreateTaskCommand('1', 'Test');

        $stamps = $decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertSame([], $stamps);
    }

    public function test_sync_mode_bypasses_attribute(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new AsyncTaskCommand('1');

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_does_not_override_existing_transport_stamp(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new AsyncTaskCommand('1');
        $existing = new TransportNamesStamp(['existing']);

        $stamps = $decider->decide($message, DispatchMode::DEFAULT, [$existing]);

        self::assertCount(1, $stamps);
        self::assertSame($existing, $stamps[0]);
    }

    public function test_async_mode_with_attribute_adds_stamp(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new AsyncTaskCommand('1');

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['async'], $stamps[0]->getTransportNames());
    }

    public function test_default_mode_with_attribute_adds_stamp(): void
    {
        $decider = new AsynchronousStampDecider();
        $message = new AsyncTaskCommand('1');

        $stamps = $decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
    }

    public function test_caches_reflection_result(): void
    {
        $decider = new AsynchronousStampDecider();
        $message1 = new AsyncTaskCommand('1');
        $message2 = new AsyncTaskCommand('2');

        $stamps1 = $decider->decide($message1, DispatchMode::DEFAULT, []);
        $stamps2 = $decider->decide($message2, DispatchMode::DEFAULT, []);

        // Both should get stamps (cache hit on second call)
        self::assertCount(1, $stamps1);
        self::assertCount(1, $stamps2);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps1[0]);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps2[0]);
    }
}
