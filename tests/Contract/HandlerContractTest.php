<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\InterfaceOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;

#[CoversNothing]
final class HandlerContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function markerInterfaces(): iterable
    {
        yield 'command' => [CommandHandler::class];
        yield 'query' => [QueryHandler::class];
        yield 'event' => [EventHandler::class];
    }

    /**
     * Declaring __invoke() on the interface would forbid implementations from type-hinting
     * the concrete message (PHP does not allow narrowing parameter types).
     *
     * @param class-string $interface
     */
    #[DataProvider('markerInterfaces')]
    public function test_handler_interfaces_are_markers_without_methods(string $interface): void
    {
        $reflection = new ReflectionClass($interface);

        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }

    public function test_implementations_can_type_hint_the_concrete_message(): void
    {
        $parameter = (new ReflectionClass(InterfaceOnlyCommandHandler::class))->getMethod('__invoke')->getParameters()[0];
        $type = $parameter->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(GenerateReportCommand::class, $type->getName());
    }
}
