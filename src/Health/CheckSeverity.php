<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

/**
 * Severity of a health check result. The highest severity is the exit code of "somework:cqrs:health".
 *
 * @api
 */
enum CheckSeverity: int
{
    case OK = 0;
    case WARNING = 1;
    case CRITICAL = 2;
}
