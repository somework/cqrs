<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Testing\CqrsTestCase;
use SomeWork\CqrsBundle\Testing\FakeCommandBus;

#[CoversClass(CqrsTestCase::class)]
final class CqrsTestCaseTest extends TestCase
{
    public function test_cqrs_test_case_extends_test_case(): void
    {
        /* @phpstan-ignore function.alreadyNarrowedType, staticMethod.alreadyNarrowedType */
        self::assertTrue(is_subclass_of(CqrsTestCase::class, TestCase::class));
    }

    public function test_cqrs_test_case_uses_assertions_trait(): void
    {
        $traits = class_uses(CqrsTestCase::class);

        self::assertIsArray($traits);
        self::assertArrayHasKey(
            'SomeWork\CqrsBundle\Testing\CqrsAssertionsTrait',
            $traits,
        );
    }

    public function test_cqrs_test_case_assert_dispatched_works(): void
    {
        // Create a concrete subclass to test the trait integration
        $testCase = new class('test') extends CqrsTestCase {
            /** @param class-string $class */
            public function runAssertDispatched(FakeCommandBus $bus, string $class): void
            {
                self::assertDispatched($bus, $class);
            }
        };

        $bus = new FakeCommandBus();
        $command = new class implements Command {};
        $bus->dispatch($command);

        $testCase->runAssertDispatched($bus, $command::class);
    }
}
