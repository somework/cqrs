<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainCommand;

/**
 * Handler using only the attribute, no CommandHandler interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
#[AsCommandHandler(command: PlainCommand::class)]
final class AttributeOnlyCommandHandler
{
    public function __invoke(PlainCommand $command): void
    {
    }
}
