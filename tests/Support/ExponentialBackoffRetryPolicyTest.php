<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy;

#[CoversClass(ExponentialBackoffRetryPolicy::class)]
final class ExponentialBackoffRetryPolicyTest extends TestCase
{
    public function test_constructor_stores_defaults_and_getters_return_them(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        self::assertSame(3, $policy->getMaxRetries());
        self::assertSame(1000, $policy->getInitialDelay());
        self::assertSame(2.0, $policy->getMultiplier());
    }

    public function test_constructor_accepts_custom_values_and_getters_return_them(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(5, 500, 1.5);

        self::assertSame(5, $policy->getMaxRetries());
        self::assertSame(500, $policy->getInitialDelay());
        self::assertSame(1.5, $policy->getMultiplier());
    }

    public function test_constructor_rejects_negative_max_retries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(-1);
    }

    public function test_constructor_rejects_initial_delay_less_than_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(3, 0);
    }

    public function test_constructor_rejects_multiplier_less_than_or_equal_to_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(3, 1000, 0.0);
    }

    public function test_get_stamps_returns_empty_array(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(3, 2000, 2.0);
        $message = new class implements Command {};

        $stamps = $policy->getStamps($message, DispatchMode::ASYNC);

        self::assertSame([], $stamps);
    }

    public function test_get_stamps_returns_empty_array_regardless_of_dispatch_mode(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(3, 1000, 2.0);
        $message = new class implements Command {};

        self::assertSame([], $policy->getStamps($message, DispatchMode::SYNC));
        self::assertSame([], $policy->getStamps($message, DispatchMode::ASYNC));
    }

    public function test_policy_implements_retry_policy_interface(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(RetryPolicy::class, $policy);
    }

    public function test_policy_implements_retry_configuration_interface(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(RetryConfiguration::class, $policy);
    }

    public function test_constructor_accepts_zero_max_retries(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(0);

        self::assertSame(0, $policy->getMaxRetries());
    }

    public function test_constructor_accepts_initial_delay_of_one(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(3, 1);

        self::assertSame(1, $policy->getInitialDelay());
    }

    public function test_constructor_accepts_sub_one_multiplier(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(3, 1000, 0.5);

        self::assertSame(0.5, $policy->getMultiplier());
    }

    public function test_constructor_rejects_multiplier_of_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(3, 1000, -1.0);
    }

    public function test_get_stamps_returns_empty_array_for_default_dispatch_mode(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();
        $message = new class implements Command {};

        self::assertSame([], $policy->getStamps($message, DispatchMode::DEFAULT));
    }

    public function test_policy_is_final_class(): void
    {
        $reflection = new \ReflectionClass(ExponentialBackoffRetryPolicy::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_constructor_accepts_large_values(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(1000, 60000, 100.0);

        self::assertSame(1000, $policy->getMaxRetries());
        self::assertSame(60000, $policy->getInitialDelay());
        self::assertSame(100.0, $policy->getMultiplier());
    }

    public function test_getters_are_idempotent(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(7, 2000, 3.0);

        self::assertSame($policy->getMaxRetries(), $policy->getMaxRetries());
        self::assertSame($policy->getInitialDelay(), $policy->getInitialDelay());
        self::assertSame($policy->getMultiplier(), $policy->getMultiplier());
    }

    public function test_get_stamps_with_different_message_types(): void
    {
        $policy = new ExponentialBackoffRetryPolicy();

        $command = new class implements Command {};
        $query = new class implements Query {};
        $event = new class implements Event {};

        self::assertSame([], $policy->getStamps($command, DispatchMode::ASYNC));
        self::assertSame([], $policy->getStamps($query, DispatchMode::SYNC));
        self::assertSame([], $policy->getStamps($event, DispatchMode::ASYNC));
    }

    public function test_constructor_rejects_negative_initial_delay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialBackoffRetryPolicy(3, -1);
    }

    public function test_constructor_accepts_small_positive_multiplier(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(3, 1000, 0.01);

        self::assertSame(0.01, $policy->getMultiplier());
    }
}
