<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use SomeWork\CqrsBundle\Exception\CqrsException;

/**
 * The setup lock stayed with a server connection of a pooler: the next setup waits for it.
 *
 * @internal
 */
final class SetupLockLeftBehind extends \RuntimeException implements CqrsException
{
}
