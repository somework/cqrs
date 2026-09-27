<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * A value of an unexpected form (e.g. a stored row or a serialized stamp).
 *
 * Catch it as CqrsException or as \UnexpectedValueException, not by this class.
 *
 * @internal
 */
final class UnexpectedValueException extends \UnexpectedValueException implements CqrsException
{
}
