<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\AbstractMessageTypeResolver;
use SomeWork\CqrsBundle\Support\RateLimitResolver;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RateLimitResolver::class)]
final class RateLimitResolverTest extends TestCase
{
    public function test_returns_null_when_no_limiter_configured(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([]));

        $result = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertNull($result);
    }

    public function test_returns_limiter_factory_for_known_message(): void
    {
        $factory = $this->createRealFactory();

        $resolver = new RateLimitResolver(new ServiceLocator([
            TaskCreatedEvent::class => static fn (): RateLimiterFactory => $factory,
        ]));

        $result = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertSame($factory, $result);
    }

    public function test_returns_limiter_factory_for_interface_match(): void
    {
        $factory = $this->createRealFactory();

        $resolver = new RateLimitResolver(new ServiceLocator([
            RetryAwareMessage::class => static fn (): RateLimiterFactory => $factory,
        ]));

        $result = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($factory, $result);
    }

    public function test_throws_when_service_is_not_rate_limiter_factory(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([
            TaskCreatedEvent::class => static fn (): string => 'invalid',
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Rate limiter for "'.TaskCreatedEvent::class.'" must be an instance of');

        $resolver->resolveFor(new TaskCreatedEvent('1'));
    }

    public function test_extends_abstract_message_type_resolver(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(RateLimitResolver::class, AbstractMessageTypeResolver::class, true));
    }

    public function test_resolve_for_returns_null_as_fallback_type(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([]));

        $result = $resolver->resolveFor(new TaskCreatedEvent('1'));

        self::assertNull($result, 'resolveFallback should return null when no limiter is configured');
    }

    public function test_throws_logic_exception_message_includes_actual_type(): void
    {
        $resolver = new RateLimitResolver(new ServiceLocator([
            TaskCreatedEvent::class => static fn (): \stdClass => new \stdClass(),
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stdClass');

        $resolver->resolveFor(new TaskCreatedEvent('1'));
    }

    public function test_exact_class_match_takes_priority_over_interface(): void
    {
        $exactFactory = $this->createRealFactory();
        $interfaceFactory = $this->createRealFactory();

        $resolver = new RateLimitResolver(new ServiceLocator([
            CreateTaskCommand::class => static fn () => $exactFactory,
            RetryAwareMessage::class => static fn () => $interfaceFactory,
        ]));

        $result = $resolver->resolveFor(new CreateTaskCommand('1', 'Test'));

        self::assertSame($exactFactory, $result);
    }

    private function createRealFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }
}
