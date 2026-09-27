<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ReportFiledEvent;

/**
 * Records events, although the root class of its hierarchy (Document) does not.
 */
#[ORM\Entity]
class Report extends Document implements RecordsEvents
{
    use RecordsEventsTrait;

    public function __construct(string $id)
    {
        parent::__construct($id);
        $this->recordThat(new ReportFiledEvent($id));
    }
}
