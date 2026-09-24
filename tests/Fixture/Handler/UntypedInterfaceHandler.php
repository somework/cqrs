<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\CommandHandler;

/**
 * Implements the marker interface without a type-hint or attribute: the handled message is unknown.
 *
 * @implements CommandHandler<\SomeWork\CqrsBundle\Contract\Command>
 */
final class UntypedInterfaceHandler implements CommandHandler
{
    public function __invoke($command): mixed
    {
        return null;
    }
}
