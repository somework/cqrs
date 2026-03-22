<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/** @api */
final class NoHandlerException extends \LogicException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly string $busName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('No handler found for "%s" dispatched on the %s bus.', $messageFqcn, $busName),
            0,
            $previous,
        );
    }
}
