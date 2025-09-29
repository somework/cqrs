<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marker interface for command messages.
 *
 * Commands are immutable data transfer objects (DTOs) describing an intention to
 * change state. They MUST NOT perform business logic and SHOULD expose their
 * data via explicit accessors.
 *
 * @psalm-immutable
 */
interface Command
{
}
