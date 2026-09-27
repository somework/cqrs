<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * Implemented by every exception the bundle throws at runtime, so callers can catch them all.
 * Messenger's own exceptions (which dispatch() lets through) do not implement it, and errors in
 * the configuration fail the container build with Symfony's configuration exceptions.
 *
 * @api
 */
interface CqrsException extends \Throwable
{
}
