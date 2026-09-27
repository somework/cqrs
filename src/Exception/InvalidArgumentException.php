<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * An invalid argument given to a class of the bundle.
 *
 * Catch it as CqrsException or as \InvalidArgumentException, not by this class.
 *
 * @internal
 */
final class InvalidArgumentException extends \InvalidArgumentException implements CqrsException
{
}
