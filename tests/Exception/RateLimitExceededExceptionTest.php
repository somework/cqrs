<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;

use function sprintf;

#[CoversClass(RateLimitExceededException::class)]
final class RateLimitExceededExceptionTest extends TestCase
{
    public function test_properties_are_accessible(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10);

        self::assertSame('App\Command\Foo', $exception->messageFqcn);
        self::assertSame($retryAfter, $exception->retryAfter);
        self::assertSame(0, $exception->remainingTokens);
        self::assertSame(10, $exception->limit);
    }

    public function test_message_format(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10);

        self::assertSame(
            'Rate limit exceeded for "App\Command\Foo". Retry after 2026-03-22T12:00:00+00:00.',
            $exception->getMessage()
        );
    }

    public function test_extends_runtime_exception(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(RateLimitExceededException::class, \RuntimeException::class, true));
    }

    public function test_previous_exception_is_preserved(): void
    {
        $previous = new \RuntimeException('inner');
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function test_previous_exception_defaults_to_null(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10);

        self::assertNull($exception->getPrevious());
    }

    public function test_code_is_zero(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10);

        self::assertSame(0, $exception->getCode());
    }

    public function test_message_uses_atom_format_for_retry_after(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-06-15T08:30:00+02:00');
        $exception = new RateLimitExceededException('App\Query\Bar', $retryAfter, 5, 100);

        self::assertSame(
            'Rate limit exceeded for "App\Query\Bar". Retry after 2026-06-15T08:30:00+02:00.',
            $exception->getMessage()
        );
    }

    public function test_is_final_class(): void
    {
        $reflection = new \ReflectionClass(RateLimitExceededException::class);

        self::assertTrue($reflection->isFinal());
    }

    public function test_readonly_properties_are_immutable(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 0, 10);

        $reflection = new \ReflectionClass($exception);

        foreach (['messageFqcn', 'retryAfter', 'remainingTokens', 'limit'] as $property) {
            self::assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                sprintf('Property "%s" should be readonly', $property),
            );
        }
    }

    public function test_non_zero_remaining_tokens(): void
    {
        $retryAfter = new \DateTimeImmutable('2026-03-22T12:00:00+00:00');
        $exception = new RateLimitExceededException('App\Command\Foo', $retryAfter, 3, 10);

        self::assertSame(3, $exception->remainingTokens);
        self::assertSame(10, $exception->limit);
    }
}
