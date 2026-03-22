<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\NoHandlerException;

#[CoversClass(NoHandlerException::class)]
final class NoHandlerExceptionTest extends TestCase
{
    public function test_message_contains_fqcn_and_bus_name(): void
    {
        $exception = new NoHandlerException('App\Command\Foo', 'command');

        self::assertStringContainsString('App\Command\Foo', $exception->getMessage());
        self::assertStringContainsString('command', $exception->getMessage());
    }

    public function test_properties_are_accessible(): void
    {
        $exception = new NoHandlerException('App\Command\Foo', 'command');

        self::assertSame('App\Command\Foo', $exception->messageFqcn);
        self::assertSame('command', $exception->busName);
    }

    public function test_extends_logic_exception(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(NoHandlerException::class, \LogicException::class, true));
    }

    public function test_previous_exception_is_preserved(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new NoHandlerException('App\Command\Foo', 'command', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function test_message_format(): void
    {
        $exception = new NoHandlerException('App\Command\Foo', 'command');

        self::assertSame(
            'No handler found for "App\Command\Foo" dispatched on the command bus.',
            $exception->getMessage()
        );
    }

    public function test_message_format_for_query_bus(): void
    {
        $exception = new NoHandlerException('App\Query\Bar', 'query');

        self::assertSame(
            'No handler found for "App\Query\Bar" dispatched on the query bus.',
            $exception->getMessage()
        );
    }

    public function test_code_is_zero(): void
    {
        $exception = new NoHandlerException('App\Command\Foo', 'command');

        self::assertSame(0, $exception->getCode());
    }

    public function test_previous_exception_defaults_to_null(): void
    {
        $exception = new NoHandlerException('App\Command\Foo', 'command');

        self::assertNull($exception->getPrevious());
    }
}
