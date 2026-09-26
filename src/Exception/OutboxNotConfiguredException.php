<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/**
 * Thrown when a message is dispatched with DispatchMode::OUTBOX while the outbox is disabled.
 *
 * @api
 */
final class OutboxNotConfiguredException extends \LogicException implements CqrsException
{
    public function __construct(
        public readonly string $messageClass,
        public readonly string $busName,
    ) {
        parent::__construct(sprintf('Message "%s" was dispatched on the %s bus with DispatchMode::OUTBOX, but the transactional outbox is disabled. Enable "somework_cqrs.outbox".', $messageClass, $busName));
    }
}
