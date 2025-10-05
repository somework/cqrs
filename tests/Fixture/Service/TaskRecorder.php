<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Service;

use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Envelope;

use function in_array;

/**
 * In-memory storage used by functional Messenger tests.
 */
final class TaskRecorder
{
    /** @var array<string, string> */
    private array $tasks = [];

    /** @var list<string> */
    private array $asyncReports = [];

    /** @var list<string> */
    private array $events = [];

    /** @var list<string> */
    private array $asyncEvents = [];

    /** @var array<string, list<class-string<object>>> */
    private array $handledMessages = [];

    /** @var array<string, list<MessageMetadataStamp>> */
    private array $metadataStamps = [];

    public function reset(): void
    {
        $this->tasks = [];
        $this->asyncReports = [];
        $this->events = [];
        $this->asyncEvents = [];
        $this->handledMessages = [];
        $this->metadataStamps = [];
    }

    public function recordTask(string $id, string $name): void
    {
        $this->tasks[$id] = $name;
    }

    public function task(string $id): ?string
    {
        return $this->tasks[$id] ?? null;
    }

    public function recordReport(string $reportId): void
    {
        $this->asyncReports[] = $reportId;
    }

    public function hasReport(string $reportId): bool
    {
        return in_array($reportId, $this->asyncReports, true);
    }

    public function recordEvent(string $taskId): void
    {
        $this->events[] = $taskId;
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function recordAsyncEvent(string $taskId): void
    {
        $this->asyncEvents[] = $taskId;
    }

    /**
     * @return list<string>
     */
    public function asyncEvents(): array
    {
        return $this->asyncEvents;
    }

    public function recordEnvelopeMessage(string $handlerClass, Envelope $envelope): void
    {
        $this->handledMessages[$handlerClass][] = $envelope->getMessage()::class;

        $metadataStamp = $envelope->last(MessageMetadataStamp::class);
        if ($metadataStamp instanceof MessageMetadataStamp) {
            $this->metadataStamps[$handlerClass][] = $metadataStamp;
        }
    }

    /**
     * @return list<class-string<object>>
     */
    public function handledMessages(string $handlerClass): array
    {
        return $this->handledMessages[$handlerClass] ?? [];
    }

    /**
     * @return list<MessageMetadataStamp>
     */
    public function metadataStamps(string $handlerClass): array
    {
        return $this->metadataStamps[$handlerClass] ?? [];
    }
}
