<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Testing\FakeQueryBus;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function sprintf;

#[CoversClass(CommandBusInterface::class)]
#[CoversClass(QueryBusInterface::class)]
#[CoversClass(EventBusInterface::class)]
final class BusInterfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function implementationProvider(): iterable
    {
        yield 'CommandBus implements CommandBusInterface' => [CommandBus::class, CommandBusInterface::class];
        yield 'FakeCommandBus implements CommandBusInterface' => [FakeCommandBus::class, CommandBusInterface::class];
        yield 'QueryBus implements QueryBusInterface' => [QueryBus::class, QueryBusInterface::class];
        yield 'FakeQueryBus implements QueryBusInterface' => [FakeQueryBus::class, QueryBusInterface::class];
        yield 'EventBus implements EventBusInterface' => [EventBus::class, EventBusInterface::class];
        yield 'FakeEventBus implements EventBusInterface' => [FakeEventBus::class, EventBusInterface::class];
    }

    /**
     * @param class-string $concreteClass
     * @param class-string $interfaceClass
     */
    #[Test]
    #[DataProvider('implementationProvider')]
    public function bus_class_implements_its_interface(string $concreteClass, string $interfaceClass): void
    {
        $reflection = new ReflectionClass($concreteClass);

        self::assertTrue(
            $reflection->implementsInterface($interfaceClass),
            sprintf('%s must implement %s', $concreteClass, $interfaceClass),
        );
    }

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
