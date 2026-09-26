<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Fixture command routed by Messenger's attribute instead of framework.messenger.routing.
 */
#[Asynchronous]
#[AsMessage(transport: 'other')]
final class AttributeRoutedCommand implements Command
{
}
