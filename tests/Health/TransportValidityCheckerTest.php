<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\TransportValidityChecker;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(TransportValidityChecker::class)]
final class TransportValidityCheckerTest extends TestCase
{
    public function test_reports_every_transport(): void
    {
        $checker = new TransportValidityChecker(new ServiceLocator([
            'async' => static fn (): InMemoryTransport => new InMemoryTransport(),
            'broken' => static fn (): never => throw new InvalidArgumentException('No transport supports the given Messenger DSN "foo://".'),
        ]));

        $results = $checker->check();

        self::assertCount(2, $results);
        self::assertSame(CheckSeverity::OK, $results[0]->severity);
        self::assertSame('transport', $results[0]->category);
        self::assertSame('Transport "async" is valid', $results[0]->message);
        self::assertSame(CheckSeverity::CRITICAL, $results[1]->severity);
        self::assertStringContainsString('Transport "broken" cannot be created: No transport supports', $results[1]->message);
    }

    public function test_reports_nothing_without_transports(): void
    {
        self::assertSame([], (new TransportValidityChecker(new ServiceLocator([])))->check());
    }
}
