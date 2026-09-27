<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marker interface for command messages.
 *
 * Commands are immutable data transfer objects (DTOs) describing an intention to
 * change state: a final class with public readonly properties, and no business
 * logic.
 *
 * @psalm-immutable
 *
 * @api
 */
interface Command
{
}
