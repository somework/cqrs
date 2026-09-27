<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AccountClosedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AccountCreditedEvent;

/**
 * Numbers its own events (SequenceAware): the counter is a column of the entity, and the version
 * column rejects a concurrent change that would give its events the same numbers. It records the
 * event of its deletion during the flush (#[ORM\PostRemove]).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cqrs_test_account')]
#[ORM\HasLifecycleCallbacks]
class Account implements RecordsEvents
{
    use RecordsEventsTrait;

    #[ORM\Column(type: Types::INTEGER)]
    private int $balance = 0;

    /** The number of the last recorded event. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $sequence = 0;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
    ) {
    }

    public function credit(int $amount): void
    {
        $this->balance += $amount;
        $this->recordThat(new AccountCreditedEvent($this->id, $amount, ++$this->sequence));
    }

    /**
     * Records its event while the flush deletes it, once it has left the identity map.
     */
    #[ORM\PostRemove]
    public function closed(): void
    {
        $this->recordThat(new AccountClosedEvent($this->id));
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function version(): int
    {
        return $this->version;
    }
}
