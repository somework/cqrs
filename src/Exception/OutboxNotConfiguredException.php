<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use SomeWork\CqrsBundle\Bus\DispatchMode;

use function sprintf;

/**
 * Thrown when a message is dispatched with DispatchMode::OUTBOX (explicitly, or resolved from
 * #[Outbox] or "dispatch_modes") while the outbox is disabled.
 *
 * @api
 */
final class OutboxNotConfiguredException extends \LogicException implements CqrsException
{
    public function __construct(
        public readonly string $messageClass,
        public readonly string $busName,
        DispatchMode $requestedMode = DispatchMode::OUTBOX,
    ) {
        parent::__construct(sprintf(
            'Message "%s" was dispatched on the %s bus with DispatchMode::OUTBOX%s, but the transactional outbox is disabled. Enable "somework_cqrs.outbox".',
            $messageClass,
            $busName,
            DispatchMode::OUTBOX === $requestedMode ? '' : ' (resolved from #[Outbox] or "somework_cqrs.dispatch_modes")',
        ));
    }
}
