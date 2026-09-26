<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Query;

/**
 * Fixture of a mistake: queries are always handled synchronously.
 *
 * @implements Query<null>
 */
#[Asynchronous]
final class AsynchronousQuery implements Query
{
}
