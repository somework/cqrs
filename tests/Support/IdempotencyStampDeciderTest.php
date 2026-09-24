<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use SomeWork\CqrsBundle\Support\IdempotencyStampDecider;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_values;

#[CoversClass(IdempotencyStampDecider::class)]
final class IdempotencyStampDeciderTest extends TestCase
{
    public function test_returns_stamps_unchanged_without_idempotency_stamp(): void
    {
        $stamps = [new DelayStamp(1000)];

        self::assertSame($stamps, (new IdempotencyStampDecider())->decide(new class implements Command {}, DispatchMode::DEFAULT, $stamps));
        self::assertSame([], (new IdempotencyStampDecider())->decide(new class implements Command {}, DispatchMode::DEFAULT, []));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_adds_a_namespaced_deduplicate_stamp_and_keeps_the_other_stamps(): void
    {
        $message = new class implements Command {};
        $delay = new DelayStamp(1000);
        $idempotencyStamp = new IdempotencyStamp('order-123');

        $result = (new IdempotencyStampDecider())->decide($message, DispatchMode::DEFAULT, [$delay, $idempotencyStamp]);

        self::assertCount(3, $result);
        self::assertSame($delay, $result[0]);
        self::assertSame($idempotencyStamp, $result[1]);

        $deduplicateStamp = $result[2];
        self::assertInstanceOf(DeduplicateStamp::class, $deduplicateStamp);
        self::assertSame($message::class.'::order-123', (string) $deduplicateStamp->getKey());
        self::assertSame(300.0, $deduplicateStamp->getTtl());
        self::assertFalse($deduplicateStamp->onlyDeduplicateInQueue());
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_uses_the_configured_ttl(): void
    {
        $result = (new IdempotencyStampDecider(60.0))->decide(new class implements Command {}, DispatchMode::DEFAULT, [new IdempotencyStamp('key')]);

        self::assertSame(60.0, self::deduplicateStamp($result)->getTtl());
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_keys_are_namespaced_per_message_class(): void
    {
        $decider = new IdempotencyStampDecider();
        $command = new class implements Command {};
        $event = new class implements Event {};

        $commandKey = (string) self::deduplicateStamp($decider->decide($command, DispatchMode::DEFAULT, [new IdempotencyStamp('same')]))->getKey();
        $eventKey = (string) self::deduplicateStamp($decider->decide($event, DispatchMode::DEFAULT, [new IdempotencyStamp('same')]))->getKey();

        self::assertNotSame($commandKey, $eventKey);
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_a_deduplicate_stamp_from_the_caller_wins(): void
    {
        $callerStamp = new DeduplicateStamp('custom-key');
        $stamps = [new IdempotencyStamp('key'), $callerStamp];

        self::assertSame($stamps, (new IdempotencyStampDecider())->decide(new class implements Command {}, DispatchMode::DEFAULT, $stamps));
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_the_last_idempotency_stamp_defines_the_key(): void
    {
        $message = new class implements Command {};

        $result = (new IdempotencyStampDecider())->decide($message, DispatchMode::DEFAULT, [new IdempotencyStamp('first'), new IdempotencyStamp('last')]);

        self::assertSame($message::class.'::last', (string) self::deduplicateStamp($result)->getKey());
    }

    /**
     * @return iterable<string, array{DispatchMode}>
     */
    public static function dispatchModes(): iterable
    {
        foreach (DispatchMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    #[DataProvider('dispatchModes')]
    public function test_works_for_every_dispatch_mode(DispatchMode $mode): void
    {
        $message = new class implements Command {};

        $result = (new IdempotencyStampDecider())->decide($message, $mode, [new IdempotencyStamp('key')]);

        self::assertSame($message::class.'::key', (string) self::deduplicateStamp($result)->getKey());
    }

    #[RequiresMethod(DeduplicateStamp::class, '__construct')]
    public function test_logs_the_conversion(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains('IdempotencyStampDecider'),
                self::callback(static fn (array $context): bool => isset($context['message'], $context['key'])),
            );

        (new IdempotencyStampDecider(300.0, $logger))->decide(new class implements Command {}, DispatchMode::DEFAULT, [new IdempotencyStamp('key')]);
    }

    public function test_does_not_log_without_idempotency_stamp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');

        (new IdempotencyStampDecider(300.0, $logger))->decide(new class implements Command {}, DispatchMode::DEFAULT, [new DelayStamp(1)]);
    }

    /**
     * @param array<int, StampInterface> $stamps
     */
    private static function deduplicateStamp(array $stamps): DeduplicateStamp
    {
        $found = array_values(array_filter($stamps, static fn (StampInterface $stamp): bool => $stamp instanceof DeduplicateStamp));
        self::assertCount(1, $found);

        return $found[0];
    }
}
