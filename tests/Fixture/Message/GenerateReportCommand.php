<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Command dispatched to the asynchronous command bus.
 */
final class GenerateReportCommand implements Command
{
    public function __construct(public readonly string $reportId)
    {
    }
}
