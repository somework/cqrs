<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RenameArticleCommand;

/**
 * Changes the entity and leaves the flush to the "doctrine_transaction" middleware, unless it fails
 * after flushing itself.
 */
#[AsCommandHandler(command: RenameArticleCommand::class)]
final class RenameArticleHandler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(RenameArticleCommand $command): mixed
    {
        $article = $this->entityManager->find(Article::class, $command->articleId) ?? throw new \RuntimeException('Unknown article.');
        $article->rename($command->title);

        if ($command->fail) {
            $this->entityManager->flush();

            throw new \RuntimeException('The handler failed after the flush.');
        }

        return null;
    }
}
