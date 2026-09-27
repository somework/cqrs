<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Contract\RecordsEventsTrait;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleDeletedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleFeaturedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticlePublishedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ArticleRenamedEvent;

/**
 * An entity with an application-generated id that records an event for each change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cqrs_test_article')]
class Article implements RecordsEvents
{
    use RecordsEventsTrait;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    public function __construct(string $id, string $title)
    {
        $this->id = $id;
        $this->title = $title;
        $this->recordThat(new ArticlePublishedEvent($id, $title));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
        $this->recordThat(new ArticleRenamedEvent($this->id, $title));
    }

    /**
     * Changes a mapped field without recording an event.
     */
    public function edit(string $body): void
    {
        $this->body = $body;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * Records an event without changing a mapped field: the flush has nothing to write.
     */
    public function feature(): void
    {
        $this->recordThat(new ArticleFeaturedEvent($this->id));
    }

    /**
     * Called before EntityManagerInterface::remove(): the DELETE does not keep the id.
     */
    public function delete(): void
    {
        $this->recordThat(new ArticleDeletedEvent($this->id));
    }

    /**
     * Records any event, for the tests of misbehaving middleware.
     */
    public function record(Event $event): void
    {
        $this->recordThat($event);
    }
}
