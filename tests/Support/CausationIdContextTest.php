<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
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

        $context->push(self::stamp('corr-1'));

        self::assertSame('corr-1', $context->current()?->getMessageId());
    }

    public function test_nested_push_pop_maintains_lifo_order(): void
    {
        $context = new CausationIdContext();

        $context->push(self::stamp('corr-1'));
        $context->push(self::stamp('corr-2'));
        self::assertSame('corr-2', $context->current()?->getMessageId());

        $context->pop();
        self::assertSame('corr-1', $context->current()?->getMessageId());

        $context->pop();
        self::assertNull($context->current());
    }

    public function test_pop_on_empty_stack_is_a_no_op(): void
    {
        $context = new CausationIdContext();

        $context->pop();

        self::assertNull($context->current());
    }

    public function test_reset_clears_entire_stack(): void
    {
        $context = new CausationIdContext();
        $context->push(self::stamp('corr-1'));
        $context->push(self::stamp('corr-2'));

        $context->reset();

        self::assertNull($context->current());
    }

    public function test_push_multiple_reset_current_is_null(): void
    {
        $context = new CausationIdContext();
        $context->push(self::stamp('a'));
        $context->push(self::stamp('b'));
        $context->push(self::stamp('c'));

        $context->reset();

        self::assertNull($context->current());
    }

    public function test_three_levels_deep_push_pop_maintains_lifo(): void
    {
        $context = new CausationIdContext();

        $context->push(self::stamp('level-1'));
        $context->push(self::stamp('level-2'));
        $context->push(self::stamp('level-3'));

        self::assertSame('level-3', $context->current()?->getMessageId());

        $context->pop();
        self::assertSame('level-2', $context->current()?->getMessageId());

        $context->pop();
        self::assertSame('level-1', $context->current()?->getMessageId());

        $context->pop();
        self::assertNull($context->current());
    }

    public function test_pop_after_reset_does_not_throw(): void
    {
        $context = new CausationIdContext();
        $context->push(self::stamp('a'));
        $context->reset();

        // The stack can be reset while a handler runs; the middleware still pops afterwards.
        $context->pop();

        self::assertNull($context->current());
    }

    public function test_push_after_reset_works_correctly(): void
    {
        $context = new CausationIdContext();
        $context->push(self::stamp('old-value'));
        $context->reset();

        $context->push(self::stamp('new-value'));

        self::assertSame('new-value', $context->current()?->getMessageId());
    }

    public function test_interleaved_push_pop_push_maintains_correct_state(): void
    {
        $context = new CausationIdContext();

        $context->push(self::stamp('a'));
        $context->push(self::stamp('b'));
        $context->pop();
        $context->push(self::stamp('c'));

        self::assertSame('c', $context->current()?->getMessageId());

        $context->pop();
        self::assertSame('a', $context->current()?->getMessageId());
    }

    private static function stamp(string $messageId): MessageMetadataStamp
    {
        return new MessageMetadataStamp('correlation', [], null, $messageId);
    }
}
