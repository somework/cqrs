<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Attribute;

use Attribute;

/**
 * Attribute to mark a service as a command handler.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsCommandHandler
{
    /**
     * Relaxed from class-string<Command> to support attribute-only handlers (DX-02).
     *
     * @param class-string          $command
     * @param non-empty-string|null $bus
     */
    public function __construct(
        public readonly string $command,
        public readonly ?string $bus = null,
    ) {
    }
}
