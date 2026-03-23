<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

#[CoversClass(CommandHandler::class)]
#[CoversClass(QueryHandler::class)]
#[CoversClass(EventHandler::class)]
final class HandlerContractTest extends TestCase
{
    #[Test]
    public function commandHandlerInvokeHasNoPhpTypeOnParameter(): void
    {
        $reflection = new ReflectionClass(CommandHandler::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('command', $params[0]->getName());
        self::assertNull($params[0]->getType(), 'CommandHandler::__invoke parameter must have no PHP type hint');
        self::assertSame('mixed', (string) $method->getReturnType());
    }

    #[Test]
    public function queryHandlerInvokeHasNoPhpTypeOnParameter(): void
    {
        $reflection = new ReflectionClass(QueryHandler::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('query', $params[0]->getName());
        self::assertNull($params[0]->getType(), 'QueryHandler::__invoke parameter must have no PHP type hint');
        self::assertSame('mixed', (string) $method->getReturnType());
    }

    #[Test]
    public function eventHandlerInvokeHasNoPhpTypeOnParameter(): void
    {
        $reflection = new ReflectionClass(EventHandler::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('event', $params[0]->getName());
        self::assertNull($params[0]->getType(), 'EventHandler::__invoke parameter must have no PHP type hint');
        self::assertSame('void', (string) $method->getReturnType());
    }
}
