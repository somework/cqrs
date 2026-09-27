<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Comment;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;

#[CoversClass(OutboxRequiresTransactionException::class)]
final class OutboxRequiresTransactionExceptionTest extends TestCase
{
    public function test_a_message_stored_outside_a_transaction(): void
    {
        $exception = new OutboxRequiresTransactionException(CreateTaskCommand::class);

        self::assertSame(CreateTaskCommand::class, $exception->messageClass);
        self::assertSame([], $exception->entityClasses);
        self::assertFalse($exception->afterCommit);
        self::assertStringContainsString('Message "'.CreateTaskCommand::class.'" was not stored in the outbox', $exception->getMessage());
        self::assertStringContainsString('require_transaction: false', $exception->getMessage());
    }

    public function test_events_recorded_by_entities_before_a_flush_outside_a_transaction(): void
    {
        $exception = new OutboxRequiresTransactionException(ArticlePublishedEvent::class, [Article::class, Comment::class]);

        self::assertSame([Article::class, Comment::class], $exception->entityClasses);
        self::assertFalse($exception->afterCommit);
        self::assertStringContainsString('The events recorded by '.Article::class.', '.Comment::class.' (first "'.ArticlePublishedEvent::class.'")', $exception->getMessage());
        self::assertStringContainsString('They are still recorded', $exception->getMessage());
        self::assertStringContainsString('does not apply to recorded events', $exception->getMessage());
    }

    public function test_events_recorded_during_a_flush_that_committed(): void
    {
        $exception = new OutboxRequiresTransactionException(ArticlePublishedEvent::class, [Article::class], true);

        self::assertTrue($exception->afterCommit);
        self::assertStringContainsString('during a flush', $exception->getMessage());
        self::assertStringContainsString('already committed without them', $exception->getMessage());
        self::assertStringContainsString('the next flush inside a transaction stores them', $exception->getMessage());
    }
}
