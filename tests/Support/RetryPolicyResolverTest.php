<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RetryPolicyResolverTest extends TestCase
{
    public function test_returns_default_policy_when_message_not_overridden(): void
    {
        $default = $this->createMock(RetryPolicy::class);
        $resolver = new RetryPolicyResolver($default, new ServiceLocator([]));

        $policy = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($default, $policy);
    }

    public function test_returns_overridden_policy_for_known_message(): void
    {
        $default = new NullRetryPolicy();
        $override = $this->createMock(RetryPolicy::class);

        $resolver = new RetryPolicyResolver($default, new ServiceLocator([
            TaskCreatedEvent::class => static fn (): RetryPolicy => $override,
        ]));

        $policy = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertSame($override, $policy);
    }

    public function test_returns_overridden_policy_for_interface(): void
    {
        $default = new NullRetryPolicy();
        $interfacePolicy = $this->createMock(RetryPolicy::class);

        $resolver = new RetryPolicyResolver($default, new ServiceLocator([
            RetryAwareMessage::class => static fn (): RetryPolicy => $interfacePolicy,
        ]));

        $policy = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($interfacePolicy, $policy);
    }

    public function test_throws_when_override_is_not_retry_policy(): void
    {
        $resolver = new RetryPolicyResolver(new NullRetryPolicy(), new ServiceLocator([
            TaskCreatedEvent::class => static fn (): string => 'invalid',
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Retry policy override for "SomeWork\\CqrsBundle\\Tests\\Fixture\\Message\\TaskCreatedEvent" must implement SomeWork\\CqrsBundle\\Contract\\RetryPolicy.');

        $resolver->resolveFor(new TaskCreatedEvent('1'));
    }
}
