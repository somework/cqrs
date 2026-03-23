<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

/**
 * A plain command message without the Command marker interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
final class PlainCommand
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
