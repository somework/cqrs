<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Doctrine;

/**
 * An onFlush listener that fails while $fail is true: registered after the bundle's listener, the
 * flush fails before it writes anything, once the bundle collected its entities.
 */
final class FailingOnFlushListener
{
    public bool $fail = true;

    public function onFlush(): void
    {
        if ($this->fail) {
            throw new \RuntimeException('An onFlush listener failed.');
        }
    }
}
