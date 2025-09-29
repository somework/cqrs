<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;
use SomeWork\CqrsBundle\Contract\Command;

/**
 * Attribute to mark a service as a command handler.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsCommandHandler
{
    /**
     * @param class-string<Command> $command
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $command,
        public readonly ?string $bus = null,
    ) {
    }
}
