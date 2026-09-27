<?php

declare(strict_types=1);

namespace App\Task\Query;

use App\Task\Activity;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

use function array_map;

/**
 * Returns the activity entries the event handlers wrote.
 */
#[AsQueryHandler(query: ListActivity::class)]
final class ListActivityHandler implements QueryHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string>
     */
    public function __invoke(ListActivity $query): mixed
    {
        return array_map(
            static fn (Activity $activity): string => $activity->description(),
            $this->entityManager->getRepository(Activity::class)->findBy([], ['id' => 'ASC']),
        );
    }
}
