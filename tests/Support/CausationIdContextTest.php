<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Support\CausationIdContext;

#[CoversClass(CausationIdContext::class)]
final class CausationIdContextTest extends TestCase
{
    public function test_current_returns_null_on_empty_stack(): void
    {
        $context = new CausationIdContext();

        self::assertNull($context->current());
    }

    public function test_push_then_current_returns_pushed_value(): void
    {
        $context = new CausationIdContext();

        $context->push('corr-1');

        self::assertSame('corr-1', $context->current());
    }

    public function test_nested_push_pop_maintains_lifo_order(): void
    {
        $context = new CausationIdContext();

        $context->push('corr-1');
        $context->push('corr-2');
        self::assertSame('corr-2', $context->current());

        $context->pop();
        self::assertSame('corr-1', $context->current());

        $context->pop();
        self::assertNull($context->current());
    }

    public function test_pop_on_empty_stack_throws_logic_exception(): void
    {
        $context = new CausationIdContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot pop from empty causation ID stack.');

        $context->pop();
    }

    public function test_reset_clears_entire_stack(): void
    {
        $context = new CausationIdContext();
        $context->push('corr-1');
        $context->push('corr-2');

        $context->reset();

        self::assertNull($context->current());
    }

    public function test_push_multiple_reset_current_is_null(): void
    {
        $context = new CausationIdContext();
        $context->push('a');
        $context->push('b');
        $context->push('c');

        $context->reset();

        self::assertNull($context->current());
    }

    public function test_three_levels_deep_push_pop_maintains_lifo(): void
    {
        $context = new CausationIdContext();

        $context->push('level-1');
        $context->push('level-2');
        $context->push('level-3');

        self::assertSame('level-3', $context->current());

        $context->pop();
        self::assertSame('level-2', $context->current());

        $context->pop();
        self::assertSame('level-1', $context->current());

        $context->pop();
        self::assertNull($context->current());
    }

    public function test_reset_then_pop_throws_logic_exception(): void
    {
        $context = new CausationIdContext();
        $context->push('a');
        $context->push('b');
        $context->reset();

        $this->expectException(LogicException::class);

        $context->pop();
    }

    public function test_push_after_reset_works_correctly(): void
    {
        $context = new CausationIdContext();
        $context->push('old-value');
        $context->reset();

        $context->push('new-value');

        self::assertSame('new-value', $context->current());
    }

    public function test_interleaved_push_pop_push_maintains_correct_state(): void
    {
        $context = new CausationIdContext();

        $context->push('a');
        $context->push('b');
        $context->pop();
        $context->push('c');

        self::assertSame('c', $context->current());

        $context->pop();
        self::assertSame('a', $context->current());
    }
}
