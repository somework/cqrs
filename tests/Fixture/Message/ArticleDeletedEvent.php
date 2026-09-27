<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Article entity before it is removed.
 *
 * @psalm-immutable
 */
final class ArticleDeletedEvent implements Event
{
    public function __construct(
        public readonly string $articleId,
    ) {
    }
}
