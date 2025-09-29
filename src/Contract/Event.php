<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marker interface for event messages.
 *
 * Events are immutable records describing something that already happened.
 * They MUST NOT contain behavior and SHOULD expose their data via accessors.
 *
 * @psalm-immutable
 */
interface Event
{
}
