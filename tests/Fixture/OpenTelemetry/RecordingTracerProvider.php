<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * Tracer provider that records every span built through it (no SDK required).
 */
final class RecordingTracerProvider implements TracerProviderInterface, TracerInterface
{
    /** @var list<RecordingSpanBuilder> */
    public array $builders = [];

    /**
     * @param iterable<mixed> $attributes
     */
    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        return $this;
    }

    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return $this->builders[] = new RecordingSpanBuilder($spanName);
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function lastSpan(): RecordingSpan
    {
        $builder = $this->builders[array_key_last($this->builders)] ?? null;

        if (null === $builder || null === $builder->span) {
            throw new \LogicException('No span was started.');
        }

        return $builder->span;
    }
}
