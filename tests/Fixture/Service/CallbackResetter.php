<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Calls a callback when it is reset.
 */
final class CallbackResetter implements ResetInterface
{
    /**
     * @param \Closure(): void $onReset
     */
    public function __construct(private readonly \Closure $onReset)
    {
    }

    public function reset(): void
    {
        ($this->onReset)();
    }
}
