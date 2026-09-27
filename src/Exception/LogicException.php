<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * A programming or usage error the bundle finds at runtime.
 *
 * Catch it as CqrsException or as \LogicException, not by this class.
 *
 * @internal
 */
final class LogicException extends \LogicException implements CqrsException
{
}
