<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries W3C trace-context headers (traceparent/tracestate) across transports so the
 * span of a consumed message continues the trace of the dispatch that produced it.
 *
 * @api
 */
final class TraceContextStamp implements StampInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $headers,
    ) {
    }
}
