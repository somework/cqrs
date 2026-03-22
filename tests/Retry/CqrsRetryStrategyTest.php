<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Retry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Retry\CqrsRetryStrategy;
use SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

#[CoversClass(CqrsRetryStrategy::class)]
final class CqrsRetryStrategyTest extends TestCase
{
    public function test_is_retryable_returns_true_when_retry_count_below_max(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(3, 1000, 2.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(0));

        self::assertTrue($strategy->isRetryable($envelope));
    }

    public function test_is_retryable_returns_false_when_retry_count_equals_max(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(3, 1000, 2.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(3));

        self::assertFalse($strategy->isRetryable($envelope));
    }

    public function test_is_retryable_delegates_to_fallback_when_policy_not_retry_configuration(): void
    {
        $fallback = $this->createMock(RetryStrategyInterface::class);
        $fallback->expects(self::once())
            ->method('isRetryable')
            ->willReturn(false);

        $strategy = $this->createStrategy(new NullRetryPolicy(), $fallback);
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertFalse($strategy->isRetryable($envelope));
    }

    public function test_is_retryable_returns_true_when_no_fallback_and_policy_not_retry_configuration(): void
    {
        $strategy = $this->createStrategy(new NullRetryPolicy());
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertTrue($strategy->isRetryable($envelope));
    }

    public function test_get_waiting_time_computes_exponential_backoff(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(5, 1000, 2.0));
        $message = new CreateTaskCommand('id-1', 'task');

        // retryCount=0: 1000 * 2.0^0 = 1000
        $envelope0 = Envelope::wrap($message);
        self::assertSame(1000, $strategy->getWaitingTime($envelope0));

        // retryCount=1: 1000 * 2.0^1 = 2000
        $envelope1 = Envelope::wrap($message)->with(new RedeliveryStamp(1));
        self::assertSame(2000, $strategy->getWaitingTime($envelope1));

        // retryCount=2: 1000 * 2.0^2 = 4000
        $envelope2 = Envelope::wrap($message)->with(new RedeliveryStamp(2));
        self::assertSame(4000, $strategy->getWaitingTime($envelope2));
    }

    public function test_get_waiting_time_delegates_to_fallback_when_policy_not_retry_configuration(): void
    {
        $fallback = $this->createMock(RetryStrategyInterface::class);
        $fallback->expects(self::once())
            ->method('getWaitingTime')
            ->willReturn(5000);

        $strategy = $this->createStrategy(new NullRetryPolicy(), $fallback);
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertSame(5000, $strategy->getWaitingTime($envelope));
    }

    public function test_get_waiting_time_returns_zero_when_no_fallback_and_policy_not_retry_configuration(): void
    {
        $strategy = $this->createStrategy(new NullRetryPolicy());
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertSame(0, $strategy->getWaitingTime($envelope));
    }

    public function test_get_waiting_time_applies_jitter_within_expected_range(): void
    {
        $jitter = 0.1;
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(5, 1000, 2.0),
            null,
            null,
            $jitter,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(1));

        // Expected base delay: 1000 * 2.0^1 = 2000
        // Jitter range: [2000 * 0.9, 2000 * 1.1] = [1800, 2200]
        $minExpected = (int) (2000 * (1 - $jitter));
        $maxExpected = (int) (2000 * (1 + $jitter));

        for ($i = 0; $i < 10; ++$i) {
            $delay = $strategy->getWaitingTime($envelope);
            self::assertGreaterThanOrEqual($minExpected, $delay);
            self::assertLessThanOrEqual($maxExpected, $delay);
        }
    }

    public function test_get_waiting_time_with_zero_jitter_returns_exact_delay(): void
    {
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(5, 1000, 2.0),
            null,
            null,
            0.0,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(2));

        // 1000 * 2.0^2 = 4000
        self::assertSame(4000, $strategy->getWaitingTime($envelope));
    }

    public function test_get_waiting_time_caps_delay_at_max_delay(): void
    {
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(10, 1000, 10.0),
            null,
            null,
            0.0,
            5000,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(2));

        // 1000 * 10.0^2 = 100000, capped at 5000
        self::assertSame(5000, $strategy->getWaitingTime($envelope));
    }

    public function test_is_retryable_treats_missing_redelivery_stamp_as_zero_retries(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(3, 1000, 2.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        // No RedeliveryStamp means retryCount=0, maxRetries=3, so retryable
        self::assertTrue($strategy->isRetryable($envelope));
    }

    public function test_logger_receives_debug_message_when_retry_configuration_resolved(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('CqrsRetryStrategy'));

        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(3, 1000, 2.0),
            null,
            $logger,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        $strategy->isRetryable($envelope);
    }

    public function test_logger_receives_debug_message_when_falling_back(): void
    {
        $fallback = $this->createMock(RetryStrategyInterface::class);
        $fallback->method('isRetryable')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('fallback'));

        $strategy = $this->createStrategy(new NullRetryPolicy(), $fallback, $logger);
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        $strategy->isRetryable($envelope);
    }

    public function test_constructor_rejects_jitter_above_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createStrategy(new NullRetryPolicy(), null, null, 1.5);
    }

    public function test_constructor_rejects_negative_jitter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createStrategy(new NullRetryPolicy(), null, null, -0.1);
    }

    public function test_constructor_rejects_negative_max_delay(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createStrategy(new NullRetryPolicy(), null, null, 0.0, -1);
    }

    public function test_is_retryable_returns_false_when_retry_count_exceeds_max(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(3, 1000, 2.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(5));

        self::assertFalse($strategy->isRetryable($envelope));
    }

    public function test_get_waiting_time_with_multiplier_one_returns_constant_delay(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(5, 500, 1.0));
        $message = new CreateTaskCommand('id-1', 'task');

        // multiplier=1.0: 500 * 1.0^N = 500 for all N
        $envelope0 = Envelope::wrap($message);
        self::assertSame(500, $strategy->getWaitingTime($envelope0));

        $envelope3 = Envelope::wrap($message)->with(new RedeliveryStamp(3));
        self::assertSame(500, $strategy->getWaitingTime($envelope3));
    }

    public function test_get_waiting_time_returns_initial_delay_when_no_redelivery_stamp(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(5, 1500, 3.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        // No RedeliveryStamp means retryCount=0: 1500 * 3.0^0 = 1500
        self::assertSame(1500, $strategy->getWaitingTime($envelope));
    }

    public function test_get_waiting_time_max_delay_caps_jittered_delay(): void
    {
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(5, 1000, 2.0),
            null,
            null,
            1.0,
            2500,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(2));

        // Base delay: 1000 * 2.0^2 = 4000. With jitter=1.0 delay could be 0-8000.
        // maxDelay=2500 guarantees cap regardless of jitter outcome.
        for ($i = 0; $i < 20; ++$i) {
            $delay = $strategy->getWaitingTime($envelope);
            self::assertLessThanOrEqual(2500, $delay);
            self::assertGreaterThanOrEqual(0, $delay);
        }
    }

    public function test_get_waiting_time_never_returns_negative(): void
    {
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(5, 1, 1.0),
            null,
            null,
            1.0,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        // initialDelay=1, multiplier=1.0, jitter=1.0 => base=1, jittered could be 0 or negative float
        // max(0, delay) ensures non-negative result
        for ($i = 0; $i < 50; ++$i) {
            $delay = $strategy->getWaitingTime($envelope);
            self::assertGreaterThanOrEqual(0, $delay);
        }
    }

    public function test_is_retryable_passes_throwable_to_fallback(): void
    {
        $throwable = new \RuntimeException('test error');

        $fallback = $this->createMock(RetryStrategyInterface::class);
        $fallback->expects(self::once())
            ->method('isRetryable')
            ->with(self::isInstanceOf(Envelope::class), self::identicalTo($throwable))
            ->willReturn(true);

        $strategy = $this->createStrategy(new NullRetryPolicy(), $fallback);
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertTrue($strategy->isRetryable($envelope, $throwable));
    }

    public function test_get_waiting_time_passes_throwable_to_fallback(): void
    {
        $throwable = new \RuntimeException('test error');

        $fallback = $this->createMock(RetryStrategyInterface::class);
        $fallback->expects(self::once())
            ->method('getWaitingTime')
            ->with(self::isInstanceOf(Envelope::class), self::identicalTo($throwable))
            ->willReturn(3000);

        $strategy = $this->createStrategy(new NullRetryPolicy(), $fallback);
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        self::assertSame(3000, $strategy->getWaitingTime($envelope, $throwable));
    }

    public function test_is_retryable_with_max_retries_zero_always_returns_false(): void
    {
        $strategy = $this->createStrategy(new ExponentialBackoffRetryPolicy(0, 1000, 2.0));
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'));

        // maxRetries=0, retryCount=0: 0 < 0 is false
        self::assertFalse($strategy->isRetryable($envelope));
    }

    public function test_constructor_accepts_boundary_jitter_values(): void
    {
        // jitter=0.0 should not throw
        $strategy0 = $this->createStrategy(new NullRetryPolicy(), null, null, 0.0);
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(CqrsRetryStrategy::class, $strategy0);

        // jitter=1.0 should not throw
        $strategy1 = $this->createStrategy(new NullRetryPolicy(), null, null, 1.0);
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(CqrsRetryStrategy::class, $strategy1);
    }

    public function test_get_waiting_time_with_max_delay_zero_does_not_cap(): void
    {
        $strategy = $this->createStrategy(
            new ExponentialBackoffRetryPolicy(10, 1000, 10.0),
            null,
            null,
            0.0,
            0,
        );
        $envelope = Envelope::wrap(new CreateTaskCommand('id-1', 'task'))
            ->with(new RedeliveryStamp(3));

        // 1000 * 10.0^3 = 1_000_000, maxDelay=0 means no cap
        self::assertSame(1_000_000, $strategy->getWaitingTime($envelope));
    }

    private function createStrategy(
        \SomeWork\CqrsBundle\Contract\RetryPolicy $defaultPolicy,
        ?RetryStrategyInterface $fallback = null,
        ?LoggerInterface $logger = null,
        float $jitter = 0.0,
        int $maxDelay = 0,
    ): CqrsRetryStrategy {
        $resolver = new RetryPolicyResolver($defaultPolicy, new ServiceLocator([]));

        return new CqrsRetryStrategy($resolver, $fallback, $logger, $jitter, $maxDelay);
    }
}
