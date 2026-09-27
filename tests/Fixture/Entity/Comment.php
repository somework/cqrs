<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CommentPostedEvent;

/**
 * An entity whose id the database generates (IDENTITY): it records its event once the id is known,
 * during the flush (postPersist).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cqrs_test_comment')]
#[ORM\HasLifecycleCallbacks]
class Comment implements RecordsEvents
{
    use RecordsEventsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $articleId,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    #[ORM\PostPersist]
    public function posted(): void
    {
        $this->recordThat(new CommentPostedEvent((int) $this->id, $this->articleId));
    }
}
