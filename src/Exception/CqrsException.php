<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

/**
 * Implemented by every exception of the bundle, so callers can catch them all.
 *
 * @api
 */
interface CqrsException extends \Throwable
{
}
