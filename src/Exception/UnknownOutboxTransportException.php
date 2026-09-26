<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;

use function implode;
use function sprintf;

/**
 * Thrown instead of storing a message in the outbox for a transport that is not a Messenger
 * transport: the relay could never send it, while the business change would commit.
 *
 * @api
 */
final class UnknownOutboxTransportException extends \LogicException implements CqrsException
{
    /**
     * @param list<string> $knownTransports
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $transportName,
        array $knownTransports,
    ) {
        parent::__construct(sprintf(
            'Message "%s" was not stored in the outbox: "%s" is not a Messenger transport (%s). %s',
            $messageClass,
            $transportName,
            [] === $knownTransports ? 'no transport is defined' : 'defined: '.implode(', ', $knownTransports),
            MessageTransportStampDecider::DEFAULT_ASYNC_TRANSPORT === $transportName
                ? 'A bare #[Outbox] or #[Asynchronous] sends to the "async" transport when nothing else names one: name a transport in the attribute, in "somework_cqrs.transports" or in framework.messenger.routing, or define an "async" transport.'
                : 'Fix the transport named by #[Outbox], #[Asynchronous], "somework_cqrs.transports" or the OutboxWriter::store() call.',
        ));
    }
}
