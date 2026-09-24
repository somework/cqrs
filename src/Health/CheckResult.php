<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

/**
 * A single finding reported by a HealthChecker.
 *
 * @api
 */
final class CheckResult
{
    public function __construct(
        public readonly CheckSeverity $severity,
        public readonly string $category,
        public readonly string $message,
    ) {
    }
}
