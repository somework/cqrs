<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Registry;

/**
 * The kind of a CQRS message.
 *
 * @api
 */
enum MessageType: string
{
    case Command = 'command';
    case Query = 'query';
    case Event = 'event';
}
