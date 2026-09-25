<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/** @api */
final class AsyncBusNotConfiguredException extends \LogicException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly string $busName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Asynchronous %s bus is not configured. Cannot dispatch "%s" in async mode. Set "somework_cqrs.buses.%s_async" to a Messenger bus.',
                $busName,
                $messageFqcn,
                $busName,
            ),
            0,
            $previous,
        );
    }
}
