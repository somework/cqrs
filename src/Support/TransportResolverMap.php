<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;

/**
 * @internal
 */
final readonly class TransportResolverMap
{
    public function __construct(
        public ?MessageTransportResolver $sync = null,
        public ?MessageTransportResolver $async = null,
    ) {
    }

    public function resolverFor(DispatchMode $mode): ?MessageTransportResolver
    {
        return match ($mode) {
            DispatchMode::ASYNC => $this->async,
            default => $this->sync,
        };
    }
}
