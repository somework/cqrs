<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Contract\RetryPolicy;

use function sprintf;

#[CoversClass(RetryConfiguration::class)]
final class RetryConfigurationTest extends TestCase
{
    public function test_interface_declares_get_max_retries_method(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        self::assertTrue($reflection->hasMethod('getMaxRetries'));

        $method = $reflection->getMethod('getMaxRetries');
        self::assertSame('int', $method->getReturnType() instanceof \ReflectionNamedType ? $method->getReturnType()->getName() : null);
        self::assertCount(0, $method->getParameters());
    }

    public function test_interface_declares_get_initial_delay_method(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        self::assertTrue($reflection->hasMethod('getInitialDelay'));

        $method = $reflection->getMethod('getInitialDelay');
        self::assertSame('int', $method->getReturnType() instanceof \ReflectionNamedType ? $method->getReturnType()->getName() : null);
        self::assertCount(0, $method->getParameters());
    }

    public function test_interface_declares_get_multiplier_method(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        self::assertTrue($reflection->hasMethod('getMultiplier'));

        $method = $reflection->getMethod('getMultiplier');
        self::assertSame('float', $method->getReturnType() instanceof \ReflectionNamedType ? $method->getReturnType()->getName() : null);
        self::assertCount(0, $method->getParameters());
    }

    public function test_interface_does_not_extend_retry_policy(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        self::assertFalse($reflection->isSubclassOf(RetryPolicy::class));
    }

    public function test_interface_is_marked_as_api(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        $docComment = $reflection->getDocComment();
        self::assertIsString($docComment);
        self::assertStringContainsString('@api', $docComment);
    }

    public function test_interface_has_exactly_three_methods(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        self::assertCount(3, $reflection->getMethods());
    }

    public function test_all_methods_are_public(): void
    {
        $reflection = new \ReflectionClass(RetryConfiguration::class);

        foreach ($reflection->getMethods() as $method) {
            self::assertTrue($method->isPublic(), sprintf('Method %s must be public', $method->getName()));
        }
    }
}
