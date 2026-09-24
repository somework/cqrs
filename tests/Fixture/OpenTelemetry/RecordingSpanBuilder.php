<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

use function bin2hex;
use function random_bytes;

final class RecordingSpanBuilder implements SpanBuilderInterface
{
    public int $kind = SpanKind::KIND_INTERNAL;

    /** @var array<string, mixed> */
    public array $attributes = [];

    public ?SpanContextInterface $parent = null;

    public ?RecordingSpan $span = null;

    public function __construct(public readonly string $name)
    {
    }

    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        if ($context instanceof ContextInterface) {
            $this->parent = Span::fromContext($context)->getContext();
        }

        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }

        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        $this->kind = $spanKind;

        return $this;
    }

    public function startSpan(): SpanInterface
    {
        // Like the SDK: without an explicit parent, the current context is the parent.
        $parent = $this->parent ?? Span::fromContext(Context::getCurrent())->getContext();
        $traceId = $parent->isValid() ? $parent->getTraceId() : bin2hex(random_bytes(16));

        return $this->span = new RecordingSpan(SpanContext::create($traceId, bin2hex(random_bytes(8)), TraceFlags::SAMPLED));
    }
}
