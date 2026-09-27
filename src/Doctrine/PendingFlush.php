<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Doctrine;

use Doctrine\ORM\UnitOfWork;
use SomeWork\CqrsBundle\Contract\RecordsEvents;

/**
 * The entities of a flush of one entity manager that postFlush looks at: those with recorded events
 * that onFlush collected, and those of recording classes about to be deleted. Also the state of the
 * store in postFlush.
 *
 * @internal
 */
final class PendingFlush
{
    /** @var array<int, RecordsEvents> By object id, in the order they were collected */
    public array $entities = [];

    /** Whether the entity manager was cleared (or closed) since the entities were collected */
    public bool $cleared = false;

    /** Whether postFlush is storing the events */
    public bool $storing = false;

    /** A failure raised while storing, also when a middleware swallowed it */
    public ?\Throwable $failure = null;

    /**
     * @param UnitOfWork $unitOfWork The unit of work of the entities: a reset entity manager gets another one
     */
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
    ) {
    }

    public function hasEvents(): bool
    {
        foreach ($this->entities as $entity) {
            if ([] !== $entity->recordedEvents()) {
                return true;
            }
        }

        return false;
    }
}
