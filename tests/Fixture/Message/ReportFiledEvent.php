<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Report entity, a subclass of a root entity that records nothing.
 *
 * @psalm-immutable
 */
final class ReportFiledEvent implements Event
{
    public function __construct(
        public readonly string $reportId,
    ) {
    }
}
