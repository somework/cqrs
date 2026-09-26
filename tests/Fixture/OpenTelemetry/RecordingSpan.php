<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use Throwable;

final class RecordingSpan extends Span
{
    public ?string $statusCode = null;

    public ?string $statusDescription = null;

    /** @var list<Throwable> */
    public array $exceptions = [];

    public bool $ended = false;

    public function __construct(private readonly SpanContextInterface $context)
    {
    }

    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return !$this->ended;
    }

    /**
     * @param bool|int|float|string|array<mixed>|null $value
     */
    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        $this->exceptions[] = $exception;

        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        $this->statusCode = $code;
        $this->statusDescription = $description;

        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        $this->ended = true;
    }
}
