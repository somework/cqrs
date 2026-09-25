<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;
use function strrpos;
use function substr;
use function ucfirst;

/** @api */
final class NoHandlerException extends \LogicException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly string $busName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'No handler found for "%s" dispatched on the %s bus. Register one with #[As%sHandler(%s::class)] or by implementing %sHandler; "bin/console somework:cqrs:list" shows the registered handlers.',
                $messageFqcn,
                $busName,
                ucfirst($busName),
                self::shortName($messageFqcn),
                ucfirst($busName),
            ),
            0,
            $previous,
        );
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
