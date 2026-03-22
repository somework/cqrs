<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

enum CheckSeverity: int
{
    case OK = 0;
    case WARNING = 1;
    case CRITICAL = 2;
}
