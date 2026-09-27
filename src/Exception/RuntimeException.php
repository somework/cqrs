<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * An error that only shows at runtime (e.g. the outbox storage failed).
 *
 * Catch it as CqrsException or as \RuntimeException, not by this class.
 *
 * @internal
 */
final class RuntimeException extends \RuntimeException implements CqrsException
{
}
