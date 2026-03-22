<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;

#[CoversClass(AsyncBusNotConfiguredException::class)]
final class AsyncBusNotConfiguredExceptionTest extends TestCase
{
    public function test_message_contains_fqcn_and_bus_name(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command');

        self::assertStringContainsString('App\Command\Foo', $exception->getMessage());
        self::assertStringContainsString('command', $exception->getMessage());
    }

    public function test_properties_are_accessible(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command');

        self::assertSame('App\Command\Foo', $exception->messageFqcn);
        self::assertSame('command', $exception->busName);
    }

    public function test_extends_logic_exception(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType */
        self::assertTrue(is_a(AsyncBusNotConfiguredException::class, \LogicException::class, true));
    }

    public function test_previous_exception_is_preserved(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    public function test_message_format(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command');

        self::assertSame(
            'Asynchronous command bus is not configured. Cannot dispatch "App\Command\Foo" in async mode.',
            $exception->getMessage()
        );
    }

    public function test_message_format_for_event_bus(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Event\Bar', 'event');

        self::assertSame(
            'Asynchronous event bus is not configured. Cannot dispatch "App\Event\Bar" in async mode.',
            $exception->getMessage()
        );
    }

    public function test_code_is_zero(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command');

        self::assertSame(0, $exception->getCode());
    }

    public function test_previous_exception_defaults_to_null(): void
    {
        $exception = new AsyncBusNotConfiguredException('App\Command\Foo', 'command');

        self::assertNull($exception->getPrevious());
    }
}
