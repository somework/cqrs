<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Article entity when it is created.
 *
 * @psalm-immutable
 */
final class ArticlePublishedEvent implements Event
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $title,
    ) {
    }
}
