<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SomeWork\CqrsBundle\Attribute\Asynchronous;

#[CoversClass(Asynchronous::class)]
final class AsynchronousTest extends TestCase
{
    public function test_default_transport_is_null(): void
    {
        $attribute = new Asynchronous();

        self::assertNull($attribute->transport);
    }

    public function test_custom_transport(): void
    {
        $attribute = new Asynchronous(transport: 'my_transport');

        self::assertSame('my_transport', $attribute->transport);
    }

    public function test_targets_class_only(): void
    {
        $reflection = new ReflectionClass(Asynchronous::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_CLASS, $instance->flags);
    }

    public function test_is_not_repeatable(): void
    {
        $reflection = new ReflectionClass(Asynchronous::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame(0, $instance->flags & Attribute::IS_REPEATABLE);
    }
}
