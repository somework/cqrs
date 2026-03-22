<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Stamp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(IdempotencyStamp::class)]
final class IdempotencyStampTest extends TestCase
{
    public function test_constructor_stores_key_and_getter_returns_it(): void
    {
        $stamp = new IdempotencyStamp('order-123');

        self::assertSame('order-123', $stamp->getKey());
    }

    public function test_constructor_rejects_empty_string_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key cannot be empty.');

        new IdempotencyStamp('');
    }

    public function test_stamp_implements_stamp_interface(): void
    {
        $stamp = new IdempotencyStamp('some-key');

        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    public function test_constructor_rejects_whitespace_only_key_is_accepted(): void
    {
        $stamp = new IdempotencyStamp('   ');

        self::assertSame('   ', $stamp->getKey());
    }

    public function test_two_stamps_with_different_keys_are_independent(): void
    {
        $stampA = new IdempotencyStamp('key-a');
        $stampB = new IdempotencyStamp('key-b');

        self::assertSame('key-a', $stampA->getKey());
        self::assertSame('key-b', $stampB->getKey());
        self::assertNotSame($stampA->getKey(), $stampB->getKey());
    }

    public function test_stamp_with_special_characters_in_key(): void
    {
        $key = 'order:123/item:456@region=us-east';
        $stamp = new IdempotencyStamp($key);

        self::assertSame($key, $stamp->getKey());
    }
}
