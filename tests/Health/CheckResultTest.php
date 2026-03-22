<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;

use function sprintf;

#[CoversClass(CheckResult::class)]
final class CheckResultTest extends TestCase
{
    public function test_is_final_class(): void
    {
        $reflection = new \ReflectionClass(CheckResult::class);

        self::assertTrue($reflection->isFinal());
    }

    #[DataProvider('severityProvider')]
    public function test_construction_with_all_severity_levels(CheckSeverity $severity, string $category, string $message): void
    {
        $result = new CheckResult($severity, $category, $message);

        self::assertSame($severity, $result->severity);
        self::assertSame($category, $result->category);
        self::assertSame($message, $result->message);
    }

    /** @return iterable<string, array{CheckSeverity, string, string}> */
    public static function severityProvider(): iterable
    {
        yield 'ok' => [CheckSeverity::OK, 'handler', 'Handler "app.handler" is resolvable'];
        yield 'warning' => [CheckSeverity::WARNING, 'handler', 'No handlers registered'];
        yield 'critical' => [CheckSeverity::CRITICAL, 'transport', 'Transport "missing" not found'];
    }

    public function test_properties_are_readonly(): void
    {
        $reflection = new \ReflectionClass(CheckResult::class);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), sprintf('Property "%s" must be readonly', $property->getName()));
        }
    }

    public function test_properties_are_public(): void
    {
        $reflection = new \ReflectionClass(CheckResult::class);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isPublic(), sprintf('Property "%s" must be public', $property->getName()));
        }
    }
}
