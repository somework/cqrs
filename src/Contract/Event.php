<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marker interface for event messages.
 *
 * Events are immutable records describing something that already happened: a final
 * class with public readonly properties, and no behaviour.
 *
 * @psalm-immutable
 *
 * @api
 */
interface Event
{
}
