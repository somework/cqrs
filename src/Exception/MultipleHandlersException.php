<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/** @api */
final class MultipleHandlersException extends \LogicException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly string $busName,
        public readonly int $handlerCount,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Message "%s" was handled by %d handlers on the %s bus. Exactly one handler is required.',
                $messageFqcn,
                $handlerCount,
                $busName,
            ),
            0,
            $previous,
        );
    }
}
