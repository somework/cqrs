<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Report;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;

/**
 * Handles a relayed ArticleRenamedEvent by filing a Report, which records a chained event.
 */
#[AsEventHandler(event: ArticleRenamedEvent::class)]
final class ArticleRenamedHandler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(ArticleRenamedEvent $event): void
    {
        $this->entityManager->persist(new Report('report-'.$event->articleId));
    }
}
