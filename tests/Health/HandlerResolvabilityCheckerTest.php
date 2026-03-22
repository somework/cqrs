<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\HandlerResolvabilityChecker;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function in_array;

#[CoversClass(HandlerResolvabilityChecker::class)]
final class HandlerResolvabilityCheckerTest extends TestCase
{
    public function test_returns_ok_for_each_resolvable_handler(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.a', 'bus' => null],
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.b', 'bus' => null],
            ],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, ['handler.a', 'handler.b'], true));

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(2, $results);
        self::assertSame(CheckSeverity::OK, $results[0]->severity);
        self::assertSame(CheckSeverity::OK, $results[1]->severity);
        self::assertSame('handler', $results[0]->category);
    }

    public function test_returns_critical_for_unresolvable_handler(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'missing.handler', 'bus' => null],
            ],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0]->severity);
        self::assertStringContainsString('missing.handler', $results[0]->message);
    }

    public function test_returns_warning_when_no_handlers_registered(): void
    {
        $registry = $this->createRegistry([]);

        $container = $this->createMock(ContainerInterface::class);

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::WARNING, $results[0]->severity);
        self::assertStringContainsString('No handlers registered', $results[0]->message);
    }

    public function test_mixed_resolvable_and_unresolvable(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.a', 'bus' => null],
            ],
            'query' => [
                ['type' => 'query', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.b', 'bus' => null],
            ],
            'event' => [
                ['type' => 'event', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.c', 'bus' => null],
            ],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, ['handler.a', 'handler.b'], true));

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(3, $results);

        $okResults = array_filter($results, static fn ($r) => CheckSeverity::OK === $r->severity);
        $criticalResults = array_filter($results, static fn ($r) => CheckSeverity::CRITICAL === $r->severity);

        self::assertCount(2, $okResults);
        self::assertCount(1, $criticalResults);
    }

    public function test_ok_result_message_contains_service_id(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'app.handler.create_task', 'bus' => null],
            ],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(1, $results);
        self::assertStringContainsString('app.handler.create_task', $results[0]->message);
        self::assertStringContainsString('resolvable', $results[0]->message);
    }

    public function test_multiple_handlers_across_command_query_event_types(): void
    {
        $registry = $this->createRegistry([
            'command' => [
                ['type' => 'command', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.cmd', 'bus' => null],
            ],
            'query' => [
                ['type' => 'query', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.qry', 'bus' => null],
            ],
            'event' => [
                ['type' => 'event', 'message' => self::class, 'handler_class' => self::class, 'service_id' => 'handler.evt', 'bus' => null],
            ],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);

        $checker = new HandlerResolvabilityChecker($registry, $container);
        $results = $checker->check();

        self::assertCount(3, $results);

        foreach ($results as $result) {
            self::assertSame(CheckSeverity::OK, $result->severity);
            self::assertSame('handler', $result->category);
        }
    }

    /**
     * @param array<string, list<array{type: string, message: class-string, handler_class: class-string, service_id: string, bus: string|null}>> $metadata
     */
    private function createRegistry(array $metadata): HandlerRegistry
    {
        return new HandlerRegistry($metadata, new ServiceLocator([]));
    }
}
