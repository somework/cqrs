<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyStampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RetryPolicyStampDeciderTest extends TestCase
{
    public function test_appends_retry_policy_stamps_for_supported_messages(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $retryStamp = new DummyStamp('retry');

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::once())
            ->method('getStamps')
            ->with($message, DispatchMode::ASYNC)
            ->willReturn([$retryStamp]);

        $resolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $decider = new RetryPolicyStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, []);

        self::assertSame([$retryStamp], $stamps);
    }

    public function test_ignores_messages_of_unexpected_type(): void
    {
        $event = new TaskCreatedEvent('123');
        $existing = new DummyStamp('existing');

        $policy = $this->createMock(RetryPolicy::class);
        $policy->expects(self::never())->method('getStamps');

        $resolver = new RetryPolicyResolver($policy, new ServiceLocator([]));
        $decider = new RetryPolicyStampDecider($resolver, Command::class);

        $stamps = $decider->decide($event, DispatchMode::ASYNC, [$existing]);

        self::assertSame([$existing], $stamps);
    }
}
