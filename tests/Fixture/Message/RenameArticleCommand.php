<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Renames an Article of DoctrineEventsTestKernel; with $fail, the handler fails after it flushed.
 *
 * @psalm-immutable
 */
final class RenameArticleCommand implements Command
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $title,
        public readonly bool $fail = false,
    ) {
    }
}
