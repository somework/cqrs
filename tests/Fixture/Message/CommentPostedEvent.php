<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Comment entity once the database generated its id (postPersist).
 *
 * @psalm-immutable
 */
final class CommentPostedEvent implements Event
{
    public function __construct(
        public readonly int $commentId,
        public readonly string $articleId,
    ) {
    }
}
