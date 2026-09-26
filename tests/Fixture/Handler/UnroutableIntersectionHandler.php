<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;

/**
 * Intersection of two interfaces: no single member satisfies the other, so Messenger cannot route it.
 */
final class UnroutableIntersectionHandler
{
    public function __invoke(Command&RetryAwareMessage $message): void
    {
    }
}
