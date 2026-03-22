<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(IdempotencyStampDecider::class)]
final class IdempotencyStampDeciderTest extends TestCase
{
    private IdempotencyStampDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new IdempotencyStampDecider();
    }

    public function test_implements_stamp_decider_interface(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(StampDecider::class, $this->decider);
    }

    public function test_returns_stamps_unchanged_when_no_idempotency_stamp(): void
    {
        $message = new class implements Command {};
        $delay = new DelayStamp(1000);
        $stamps = [$delay];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertSame($stamps, $result);
    }

    public function test_returns_stamps_unchanged_when_stamps_empty(): void
    {
        $message = new class implements Command {};

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, []);

        self::assertSame([], $result);
    }

    public function test_converts_idempotency_stamp_to_deduplicate_stamp_with_namespaced_key(): void
    {
        $message = new class implements Command {};
        $idempotencyStamp = new IdempotencyStamp('order-123');
        $stamps = [$idempotencyStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertCount(1, $result);

        $deduplicateStamps = array_filter(
            $result,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );
        self::assertCount(1, $deduplicateStamps);

        $deduplicateStamp = reset($deduplicateStamps);
        self::assertSame($message::class.'::order-123', (string) $deduplicateStamp->getKey());
    }

    public function test_uses_configured_default_ttl(): void
    {
        $decider = new IdempotencyStampDecider(600.0);
        $message = new class implements Command {};
        $stamps = [new IdempotencyStamp('key-1')];

        $result = $decider->decide($message, DispatchMode::DEFAULT, $stamps);

        $deduplicateStamps = array_filter(
            $result,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );
        $deduplicateStamp = reset($deduplicateStamps);
        /* @phpstan-ignore method.nonObject */
        self::assertSame(600.0, $deduplicateStamp->getTtl());
    }

    public function test_passes_only_deduplicate_in_queue_false(): void
    {
        $message = new class implements Command {};
        $stamps = [new IdempotencyStamp('key-1')];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        $deduplicateStamps = array_filter(
            $result,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );
        $deduplicateStamp = reset($deduplicateStamps);
        /* @phpstan-ignore method.nonObject */
        self::assertFalse($deduplicateStamp->onlyDeduplicateInQueue());
    }

    public function test_preserves_all_other_stamps(): void
    {
        $message = new class implements Command {};
        $delay = new DelayStamp(1000);
        $idempotencyStamp = new IdempotencyStamp('key-1');
        $stamps = [$delay, $idempotencyStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        self::assertCount(2, $result);
        self::assertInstanceOf(DelayStamp::class, $result[0]);
    }

    public function test_different_message_types_same_key_produce_different_deduplicate_keys(): void
    {
        $commandMessage = new class implements Command {};
        $eventMessage = new class implements Event {};
        $key = 'same-key';

        $commandResult = $this->decider->decide($commandMessage, DispatchMode::DEFAULT, [new IdempotencyStamp($key)]);
        $eventResult = $this->decider->decide($eventMessage, DispatchMode::DEFAULT, [new IdempotencyStamp($key)]);

        $commandDedup = array_filter(
            $commandResult,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );
        $eventDedup = array_filter(
            $eventResult,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );

        /* @phpstan-ignore method.nonObject */
        $commandKey = (string) reset($commandDedup)->getKey();
        /* @phpstan-ignore method.nonObject */
        $eventKey = (string) reset($eventDedup)->getKey();

        self::assertNotSame($commandKey, $eventKey);
        self::assertStringContainsString($commandMessage::class, $commandKey);
        self::assertStringContainsString($eventMessage::class, $eventKey);
    }

    public function test_removes_idempotency_stamp_from_output(): void
    {
        $message = new class implements Command {};
        $stamps = [new IdempotencyStamp('key-1')];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        $idempotencyStamps = array_filter(
            $result,
            static fn (StampInterface $s): bool => $s instanceof IdempotencyStamp,
        );
        self::assertCount(0, $idempotencyStamps);
    }

    public function test_logs_debug_message_when_converting(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains('IdempotencyStampDecider'),
                self::callback(static function (array $context): bool {
                    self::assertArrayHasKey('message', $context);
                    self::assertArrayHasKey('key', $context);

                    return true;
                }),
            );

        $decider = new IdempotencyStampDecider(300.0, $logger);
        $message = new class implements Command {};

        $decider->decide($message, DispatchMode::DEFAULT, [new IdempotencyStamp('key-1')]);
    }

    public function test_default_ttl_is_300(): void
    {
        $message = new class implements Command {};
        $stamps = [new IdempotencyStamp('key-1')];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        $deduplicateStamps = array_filter(
            $result,
            static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
        );
        $deduplicateStamp = reset($deduplicateStamps);
        /* @phpstan-ignore method.nonObject */
        self::assertSame(300.0, $deduplicateStamp->getTtl());
    }

    public function test_does_not_log_when_no_idempotency_stamp_present(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');

        $decider = new IdempotencyStampDecider(300.0, $logger);
        $message = new class implements Command {};

        $decider->decide($message, DispatchMode::DEFAULT, [new DelayStamp(1000)]);
    }

    public function test_works_with_all_dispatch_modes(): void
    {
        $message = new class implements Command {};

        foreach ([DispatchMode::SYNC, DispatchMode::ASYNC, DispatchMode::DEFAULT] as $mode) {
            $result = $this->decider->decide($message, $mode, [new IdempotencyStamp('key-1')]);

            $deduplicateStamps = array_filter(
                $result,
                static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
            );
            self::assertCount(1, $deduplicateStamps, 'DeduplicateStamp should be produced for mode '.$mode->value);
        }
    }

    public function test_reindexes_stamps_array_after_removal(): void
    {
        $message = new class implements Command {};
        $delay = new DelayStamp(1000);
        $idempotencyStamp = new IdempotencyStamp('key-1');
        $stamps = [$delay, $idempotencyStamp];

        $result = $this->decider->decide($message, DispatchMode::DEFAULT, $stamps);

        // Keys must be sequential 0-based after array_values()
        self::assertSame([0, 1], array_keys($result));
    }

    public function test_handles_idempotency_stamp_at_different_positions(): void
    {
        $message = new class implements Command {};
        $delay1 = new DelayStamp(100);
        $delay2 = new DelayStamp(200);
        $idempotencyStamp = new IdempotencyStamp('pos-key');

        // IdempotencyStamp at position 0
        $result0 = $this->decider->decide($message, DispatchMode::DEFAULT, [$idempotencyStamp, $delay1, $delay2]);
        self::assertCount(3, $result0);

        // IdempotencyStamp at position 1
        $result1 = $this->decider->decide($message, DispatchMode::DEFAULT, [$delay1, $idempotencyStamp, $delay2]);
        self::assertCount(3, $result1);

        // IdempotencyStamp at position 2
        $result2 = $this->decider->decide($message, DispatchMode::DEFAULT, [$delay1, $delay2, $idempotencyStamp]);
        self::assertCount(3, $result2);

        // All three results should produce the same namespaced key
        $extractKey = static function (array $stamps): string {
            $dedup = array_filter(
                $stamps,
                static fn (StampInterface $s): bool => $s instanceof \Symfony\Component\Messenger\Stamp\DeduplicateStamp,
            );

            /* @phpstan-ignore method.nonObject */
            return (string) reset($dedup)->getKey();
        };

        $expectedKey = $message::class.'::pos-key';
        self::assertSame($expectedKey, $extractKey($result0));
        self::assertSame($expectedKey, $extractKey($result1));
        self::assertSame($expectedKey, $extractKey($result2));
    }
}
