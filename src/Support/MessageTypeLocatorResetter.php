<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
final class MessageTypeLocatorResetter implements ResetInterface
{
    public function reset(): void
    {
        MessageTypeLocator::reset();
    }
}
