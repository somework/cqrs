<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\TransportValidityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function in_array;

#[CoversClass(TransportValidityChecker::class)]
final class TransportValidityCheckerTest extends TestCase
{
    public function test_returns_ok_for_each_valid_transport(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, [
                'messenger.transport.async',
                'messenger.transport.failed',
            ], true));

        $checker = new TransportValidityChecker($container, ['async', 'failed']);
        $results = $checker->check();

        self::assertCount(2, $results);
        self::assertSame(CheckSeverity::OK, $results[0]->severity);
        self::assertSame(CheckSeverity::OK, $results[1]->severity);
        self::assertSame('transport', $results[0]->category);
    }

    public function test_returns_critical_for_missing_transport(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $checker = new TransportValidityChecker($container, ['missing_transport']);
        $results = $checker->check();

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0]->severity);
        self::assertStringContainsString('missing_transport', $results[0]->message);
    }

    public function test_returns_empty_when_no_transports_configured(): void
    {
        $container = $this->createMock(ContainerInterface::class);

        $checker = new TransportValidityChecker($container, []);
        $results = $checker->check();

        self::assertCount(0, $results);
    }

    public function test_service_id_uses_messenger_transport_prefix(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('has')
            ->with('messenger.transport.my_queue')
            ->willReturn(true);

        $checker = new TransportValidityChecker($container, ['my_queue']);
        $checker->check();
    }

    public function test_critical_result_message_contains_service_id(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $checker = new TransportValidityChecker($container, ['broken']);
        $results = $checker->check();

        self::assertCount(1, $results);
        self::assertStringContainsString('messenger.transport.broken', $results[0]->message);
    }

    public function test_mixed_valid_and_invalid_transports(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => 'messenger.transport.async' === $id);

        $checker = new TransportValidityChecker($container, ['async', 'bad']);
        $results = $checker->check();

        self::assertCount(2, $results);

        $okResults = array_filter($results, static fn ($r) => CheckSeverity::OK === $r->severity);
        $criticalResults = array_filter($results, static fn ($r) => CheckSeverity::CRITICAL === $r->severity);

        self::assertCount(1, $okResults);
        self::assertCount(1, $criticalResults);
    }
}
