<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use DateTimeImmutable;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;

use function array_slice;
use function in_array;
use function sprintf;

final class InMemoryOutboxStorage implements OutboxStorage
{
    /** @var array<string, OutboxMessage> */
    private array $messages = [];

    /** @var array<string, DateTimeImmutable> */
    private array $published = [];

    /** @var list<string> */
    public array $failMarkingPublished = [];

    public function store(OutboxMessage $message): void
    {
        $this->messages[$message->id] = $message;
    }

    public function fetchUnpublished(int $limit, int $offset = 0): array
    {
        $unpublished = [];
        foreach ($this->messages as $id => $message) {
            if (!isset($this->published[$id])) {
                $unpublished[] = $message;
            }
        }

        return array_slice($unpublished, $offset, $limit);
    }

    public function markPublished(string $id): void
    {
        if (in_array($id, $this->failMarkingPublished, true)) {
            throw new \RuntimeException(sprintf('Cannot mark "%s" as published.', $id));
        }

        $this->published[$id] = new DateTimeImmutable();
    }

    public function purgePublished(DateTimeImmutable $publishedBefore): int
    {
        $deleted = 0;
        foreach ($this->published as $id => $publishedAt) {
            if ($publishedAt < $publishedBefore) {
                unset($this->messages[$id], $this->published[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function isPublished(string $id): bool
    {
        return isset($this->published[$id]);
    }
}
