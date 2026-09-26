<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\HandlerResolvabilityChecker;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function array_map;

#[CoversClass(HandlerResolvabilityChecker::class)]
final class HandlerResolvabilityCheckerTest extends TestCase
{
    public function test_reports_every_instantiable_handler_as_ok(): void
    {
        $checker = new HandlerResolvabilityChecker(
            self::registry(['handler.a', 'handler.b']),
            new ServiceLocator(['handler.a' => static fn (): object => new \stdClass(), 'handler.b' => static fn (): object => new \stdClass()]),
        );

        $results = $checker->check();

        self::assertSame([CheckSeverity::OK, CheckSeverity::OK], self::severities($results));
        self::assertSame('handler', $results[0]->category);
        self::assertStringContainsString('"handler.a" is resolvable', $results[0]->message);
    }

    public function test_a_handler_on_several_buses_is_checked_once(): void
    {
        $instantiations = 0;
        $checker = new HandlerResolvabilityChecker(
            self::registry(['handler.a', 'handler.a']),
            new ServiceLocator(['handler.a' => static function () use (&$instantiations): object {
                ++$instantiations;

                return new \stdClass();
            }]),
        );

        self::assertCount(1, $checker->check());
        self::assertSame(1, $instantiations);
    }

    public function test_a_missing_handler_service_is_critical(): void
    {
        $results = (new HandlerResolvabilityChecker(self::registry(['missing.handler']), new ServiceLocator([])))->check();

        self::assertSame([CheckSeverity::CRITICAL], self::severities($results));
        self::assertStringContainsString('"missing.handler" is not resolvable', $results[0]->message);
    }

    public function test_a_handler_that_cannot_be_instantiated_is_critical(): void
    {
        $checker = new HandlerResolvabilityChecker(
            self::registry(['handler.broken']),
            new ServiceLocator(['handler.broken' => static fn (): object => throw new \RuntimeException('Environment variable not found: "API_KEY".')]),
        );

        $results = $checker->check();

        self::assertSame([CheckSeverity::CRITICAL], self::severities($results));
        self::assertStringContainsString('"handler.broken" cannot be instantiated: Environment variable not found', $results[0]->message);
    }

    public function test_warns_when_no_handler_is_registered(): void
    {
        $results = (new HandlerResolvabilityChecker(self::registry([]), new ServiceLocator([])))->check();

        self::assertSame([CheckSeverity::WARNING], self::severities($results));
        self::assertStringContainsString('No handlers registered', $results[0]->message);
    }

    /**
     * @param list<string> $serviceIds
     */
    private static function registry(array $serviceIds): HandlerRegistry
    {
        $entries = array_map(
            static fn (string $serviceId): array => ['type' => 'command', 'message' => \stdClass::class, 'handler_class' => \stdClass::class, 'service_id' => $serviceId, 'bus' => null],
            $serviceIds,
        );

        return new HandlerRegistry(['command' => $entries], new ServiceLocator([]));
    }

    /**
     * @param list<CheckResult> $results
     *
     * @return list<CheckSeverity>
     */
    private static function severities(array $results): array
    {
        return array_map(static fn (CheckResult $result): CheckSeverity => $result->severity, $results);
    }
}
