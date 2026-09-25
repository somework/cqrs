<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function implode;
use function sprintf;

/**
 * Thrown when a message that must be handled synchronously (CommandBus::dispatchSync(),
 * QueryBus::ask()) was sent to a transport by the Messenger routing instead.
 *
 * @api
 */
final class MessageSentToTransportException extends \LogicException implements CqrsException
{
    /**
     * @param list<string> $transportNames
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $busName,
        public readonly array $transportNames,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Message "%s" dispatched on the %s bus was sent to transport(s) "%s" instead of being handled synchronously, so no result is available. Remove it from the async routing (framework.messenger.routing / somework_cqrs.transports) or dispatch it asynchronously.',
                $messageClass,
                $busName,
                implode('", "', $transportNames),
            ),
            0,
            $previous,
        );
    }
}
