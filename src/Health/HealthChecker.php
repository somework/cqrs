<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

interface HealthChecker
{
    /** @return list<CheckResult> */
    public function check(): array;
}
