<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use SomeWork\CqrsBundle\Support\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Support\RateLimitResolver;
use SomeWork\CqrsBundle\Support\RateLimitStampDecider;
use SomeWork\CqrsBundle\Support\StampDecider;
use SomeWork\CqrsBundle\Tests\Fixture\DummyStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RateLimitStampDecider::class)]
final class RateLimitStampDeciderTest extends TestCase
{
    public function test_returns_stamps_unchanged_for_unsupported_message_type(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([]));
        $decider = new RateLimitStampDecider($resolver, Command::class);
        $existing = [new DummyStamp('existing')];

        $stamps = $decider->decide(new TaskCreatedEvent('123'), DispatchMode::SYNC, $existing);

        self::assertSame($existing, $stamps);
    }

    public function test_returns_stamps_unchanged_when_no_limiter_configured(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $existing = [new DummyStamp('existing')];

        $resolver = new RateLimitResolver(new ServiceLocator([]));
        $decider = new RateLimitStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::SYNC, $existing);

        self::assertSame($existing, $stamps);
    }

    public function test_returns_stamps_unchanged_when_rate_limit_is_accepted(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $existing = [new DummyStamp('existing')];

        $factory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );

        $resolver = new RateLimitResolver(new ServiceLocator([
            CreateTaskCommand::class => static fn () => $factory,
        ]));

        $decider = new RateLimitStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::SYNC, $existing);

        self::assertSame($existing, $stamps);
    }

    public function test_throws_when_rate_limit_is_rejected(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        // Fixed window: 1 token per interval. Consume it first, then the second call should throw.
        $factory = new RateLimiterFactory(
            ['id' => 'rate_test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        $resolver = new RateLimitResolver(new ServiceLocator([
            CreateTaskCommand::class => static fn () => $factory,
        ]));

        $decider = new RateLimitStampDecider($resolver, Command::class);

        // First call consumes the single token
        $decider->decide($message, DispatchMode::SYNC, []);

        // Second call should throw
        $this->expectException(RateLimitExceededException::class);

        $decider->decide($message, DispatchMode::SYNC, []);
    }

    public function test_exception_carries_rate_limit_metadata(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $factory = new RateLimiterFactory(
            ['id' => 'meta_test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        $resolver = new RateLimitResolver(new ServiceLocator([
            CreateTaskCommand::class => static fn () => $factory,
        ]));

        $decider = new RateLimitStampDecider($resolver, Command::class);

        // First call consumes the token
        $decider->decide($message, DispatchMode::SYNC, []);

        try {
            $decider->decide($message, DispatchMode::SYNC, []);
            self::fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            self::assertSame(CreateTaskCommand::class, $e->messageFqcn);
            /* @phpstan-ignore staticMethod.alreadyNarrowedType */
            self::assertInstanceOf(\DateTimeImmutable::class, $e->retryAfter);
            self::assertSame(0, $e->remainingTokens);
            self::assertSame(1, $e->limit);
        }
    }

    public function test_message_types_returns_configured_type(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([]));
        $decider = new RateLimitStampDecider($resolver, Command::class);

        self::assertSame([Command::class], $decider->messageTypes());
    }

    public function test_implements_message_type_aware_stamp_decider(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(RateLimitStampDecider::class, MessageTypeAwareStampDecider::class, true));
    }

    public function test_implements_stamp_decider(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(RateLimitStampDecider::class, StampDecider::class, true));
    }

    public function test_is_final_class(): void
    {
        $reflection = new \ReflectionClass(RateLimitStampDecider::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_passes_empty_stamps_array_when_accepted(): void
    {
        $message = new CreateTaskCommand('123', 'Test');

        $factory = new RateLimiterFactory(
            ['id' => 'empty_test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );

        $resolver = new RateLimitResolver(new ServiceLocator([
            CreateTaskCommand::class => static fn () => $factory,
        ]));

        $decider = new RateLimitStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::SYNC, []);

        self::assertSame([], $stamps);
    }

    public function test_does_not_modify_stamps_on_async_dispatch_mode(): void
    {
        $message = new CreateTaskCommand('123', 'Test');
        $existing = [new DummyStamp('existing')];

        $resolver = new RateLimitResolver(new ServiceLocator([]));
        $decider = new RateLimitStampDecider($resolver, Command::class);

        $stamps = $decider->decide($message, DispatchMode::ASYNC, $existing);

        self::assertSame($existing, $stamps);
    }

    public function test_different_message_types_use_separate_limiters(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([]));
        $commandDecider = new RateLimitStampDecider($resolver, Command::class);
        $eventDecider = new RateLimitStampDecider($resolver, Event::class);

        self::assertSame([Command::class], $commandDecider->messageTypes());
        self::assertSame([Event::class], $eventDecider->messageTypes());
    }
}
