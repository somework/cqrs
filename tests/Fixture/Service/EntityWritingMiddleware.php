<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Document;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

use function uniqid;

/**
 * An audit middleware on the event bus that, once enabled, writes through the entity manager on
 * every dispatch: while recorded events are stored, that write would be lost.
 */
final class EntityWritingMiddleware implements MiddlewareInterface
{
    public bool $enabled = false;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($this->enabled) {
            $this->entityManager->persist(new Document(uniqid('audit-', true)));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
