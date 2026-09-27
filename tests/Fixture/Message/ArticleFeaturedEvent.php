<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Article entity without a change of its mapped fields.
 *
 * @psalm-immutable
 */
final class ArticleFeaturedEvent implements Event
{
    public function __construct(
        public readonly string $articleId,
    ) {
    }
}
