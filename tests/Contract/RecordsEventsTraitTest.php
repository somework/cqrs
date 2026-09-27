<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AssertsMessages;

#[CoversTrait(RecordsEventsTrait::class)]
final class RecordsEventsTraitTest extends TestCase
{
    use AssertsMessages;

    public function test_recorded_events_are_kept_in_order_until_they_are_released(): void
    {
        $article = new Article('a1', 'One');
        $article->rename('Two');

        $recorded = $article->recordedEvents();
        self::assertSameMessages([new ArticlePublishedEvent('a1', 'One'), new ArticleRenamedEvent('a1', 'Two')], $recorded);
        self::assertSame($recorded, $article->recordedEvents(), 'Peeking keeps the events.');

        self::assertSame($recorded, $article->releaseEvents());
        self::assertSame([], $article->recordedEvents());
        self::assertSame([], $article->releaseEvents());
    }

    public function test_events_recorded_after_a_release_start_a_new_list(): void
    {
        $article = new Article('a1', 'One');
        $article->releaseEvents();

        $article->rename('Two');

        self::assertSameMessages([new ArticleRenamedEvent('a1', 'Two')], $article->releaseEvents());
    }

    public function test_record_that_is_not_public(): void
    {
        self::assertFalse((new \ReflectionMethod(Article::class, 'recordThat'))->isPublic());
    }
}
