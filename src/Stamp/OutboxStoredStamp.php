<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Added to the envelope a bus returns for a message it stored in the outbox instead of sending
 * it: the relay sends the stored rows later.
 *
 * @api
 */
final class OutboxStoredStamp implements NonSendableStampInterface
{
    /**
     * @param non-empty-list<string>      $ids            The ids of the stored rows, one per transport
     * @param non-empty-list<string|null> $transportNames The transport of each row; null follows framework.messenger.routing
     */
    public function __construct(
        public readonly array $ids,
        public readonly array $transportNames,
    ) {
    }
}
