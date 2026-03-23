<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(CommandBusInterface::class)]
#[CoversClass(QueryBusInterface::class)]
#[CoversClass(EventBusInterface::class)]
final class BusInterfaceTest extends TestCase
{
    #[Test]
    public function command_bus_interface_declares_dispatch_method(): void
    {
        $reflection = new ReflectionClass(CommandBusInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('dispatch'));

        $method = $reflection->getMethod('dispatch');
        $params = $method->getParameters();

        self::assertSame('command', $params[0]->getName());
        self::assertSame(Command::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('mode', $params[1]->getName());
        self::assertSame(DispatchMode::class, self::namedTypeName($params[1]->getType()));
        self::assertTrue($params[1]->isDefaultValueAvailable());
        self::assertSame(DispatchMode::DEFAULT, $params[1]->getDefaultValue());
        self::assertSame('stamps', $params[2]->getName());
        self::assertTrue($params[2]->isVariadic());
        self::assertSame(StampInterface::class, self::namedTypeName($params[2]->getType()));
        self::assertSame(Envelope::class, self::namedTypeName($method->getReturnType()));
    }

    #[Test]
    public function command_bus_interface_declares_dispatch_sync_method(): void
    {
        $reflection = new ReflectionClass(CommandBusInterface::class);

        self::assertTrue($reflection->hasMethod('dispatchSync'));

        $method = $reflection->getMethod('dispatchSync');
        $params = $method->getParameters();

        self::assertSame('command', $params[0]->getName());
        self::assertSame(Command::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('stamps', $params[1]->getName());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame('mixed', (string) $method->getReturnType());
    }

    #[Test]
    public function command_bus_interface_declares_dispatch_async_method(): void
    {
        $reflection = new ReflectionClass(CommandBusInterface::class);

        self::assertTrue($reflection->hasMethod('dispatchAsync'));

        $method = $reflection->getMethod('dispatchAsync');
        $params = $method->getParameters();

        self::assertSame('command', $params[0]->getName());
        self::assertSame(Command::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('stamps', $params[1]->getName());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame(Envelope::class, self::namedTypeName($method->getReturnType()));
    }

    #[Test]
    public function query_bus_interface_declares_ask_method(): void
    {
        $reflection = new ReflectionClass(QueryBusInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('ask'));

        $method = $reflection->getMethod('ask');
        $params = $method->getParameters();

        self::assertSame('query', $params[0]->getName());
        self::assertSame(Query::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('stamps', $params[1]->getName());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame(StampInterface::class, self::namedTypeName($params[1]->getType()));
        self::assertSame('mixed', (string) $method->getReturnType());
    }

    #[Test]
    public function event_bus_interface_declares_dispatch_method(): void
    {
        $reflection = new ReflectionClass(EventBusInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('dispatch'));

        $method = $reflection->getMethod('dispatch');
        $params = $method->getParameters();

        self::assertSame('event', $params[0]->getName());
        self::assertSame(Event::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('mode', $params[1]->getName());
        self::assertSame(DispatchMode::class, self::namedTypeName($params[1]->getType()));
        self::assertTrue($params[1]->isDefaultValueAvailable());
        self::assertSame(DispatchMode::DEFAULT, $params[1]->getDefaultValue());
        self::assertSame('stamps', $params[2]->getName());
        self::assertTrue($params[2]->isVariadic());
        self::assertSame(Envelope::class, self::namedTypeName($method->getReturnType()));
    }

    #[Test]
    public function event_bus_interface_declares_dispatch_sync_method(): void
    {
        $reflection = new ReflectionClass(EventBusInterface::class);

        self::assertTrue($reflection->hasMethod('dispatchSync'));

        $method = $reflection->getMethod('dispatchSync');
        $params = $method->getParameters();

        self::assertSame('event', $params[0]->getName());
        self::assertSame(Event::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('stamps', $params[1]->getName());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame(Envelope::class, self::namedTypeName($method->getReturnType()));
    }

    #[Test]
    public function event_bus_interface_declares_dispatch_async_method(): void
    {
        $reflection = new ReflectionClass(EventBusInterface::class);

        self::assertTrue($reflection->hasMethod('dispatchAsync'));

        $method = $reflection->getMethod('dispatchAsync');
        $params = $method->getParameters();

        self::assertSame('event', $params[0]->getName());
        self::assertSame(Event::class, self::namedTypeName($params[0]->getType()));
        self::assertSame('stamps', $params[1]->getName());
        self::assertTrue($params[1]->isVariadic());
        self::assertSame(Envelope::class, self::namedTypeName($method->getReturnType()));
    }

    private static function namedTypeName(?\ReflectionType $type): string
    {
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }
}
