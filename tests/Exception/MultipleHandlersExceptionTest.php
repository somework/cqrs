<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;

#[CoversClass(MultipleHandlersException::class)]
final class MultipleHandlersExceptionTest extends TestCase
{
    public function test_message_contains_fqcn_bus_name_and_count(): void
    {
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3);

        self::assertStringContainsString('App\Query\Bar', $exception->getMessage());
        self::assertStringContainsString('query', $exception->getMessage());
        self::assertStringContainsString('3', $exception->getMessage());
    }

    public function test_properties_are_accessible(): void
    {
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3);

        self::assertSame('App\Query\Bar', $exception->messageFqcn);
        self::assertSame('query', $exception->busName);
        self::assertSame(3, $exception->handlerCount);
    }

    public function test_extends_logic_exception(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(MultipleHandlersException::class, \LogicException::class, true));
    }

    public function test_previous_exception_is_preserved(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function test_message_format(): void
    {
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3);

        self::assertSame(
            'Message "App\Query\Bar" was handled by 3 handlers on the query bus. Exactly one handler is required.',
            $exception->getMessage()
        );
    }

    public function test_message_format_with_two_handlers(): void
    {
        $exception = new MultipleHandlersException('App\Query\Baz', 'query', 2);

        self::assertSame(
            'Message "App\Query\Baz" was handled by 2 handlers on the query bus. Exactly one handler is required.',
            $exception->getMessage()
        );
    }

    public function test_code_is_zero(): void
    {
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3);

        self::assertSame(0, $exception->getCode());
    }

    public function test_previous_exception_defaults_to_null(): void
    {
        $exception = new MultipleHandlersException('App\Query\Bar', 'query', 3);

        self::assertNull($exception->getPrevious());
    }
}
