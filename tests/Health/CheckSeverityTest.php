<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckSeverity;

#[CoversClass(CheckSeverity::class)]
final class CheckSeverityTest extends TestCase
{
    public function test_ok_has_value_zero(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertSame(0, CheckSeverity::OK->value);
    }

    public function test_warning_has_value_one(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertSame(1, CheckSeverity::WARNING->value);
    }

    public function test_critical_has_value_two(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertSame(2, CheckSeverity::CRITICAL->value);
    }

    public function test_is_int_backed_enum(): void
    {
        $reflection = new \ReflectionEnum(CheckSeverity::class);

        self::assertTrue($reflection->isBacked());
        $backingType = $reflection->getBackingType();
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(\ReflectionNamedType::class, $backingType);
        self::assertSame('int', $backingType->getName());
    }

    public function test_has_exactly_three_cases(): void
    {
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertCount(3, CheckSeverity::cases());
    }

    public function test_all_expected_cases_exist(): void
    {
        $names = array_map(static fn (CheckSeverity $s): string => $s->name, CheckSeverity::cases());

        self::assertContains('OK', $names);
        self::assertContains('WARNING', $names);
        self::assertContains('CRITICAL', $names);
    }

    public function test_values_are_ordered_by_increasing_severity(): void
    {
        self::assertLessThan(CheckSeverity::WARNING->value, CheckSeverity::OK->value);
        self::assertLessThan(CheckSeverity::CRITICAL->value, CheckSeverity::WARNING->value);
    }
}
