<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

/**
 * Contributes results to the "somework:cqrs:health" command.
 *
 * Services implementing this interface are autoconfigured with the "somework_cqrs.health_checker" tag.
 *
 * @api
 */
interface HealthChecker
{
    /** @return list<CheckResult> */
    public function check(): array;
}
