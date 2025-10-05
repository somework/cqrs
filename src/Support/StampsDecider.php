<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Aggregates registered stamp deciders.
 */
final class StampsDecider implements StampDecider
{
    /**
     * @param iterable<StampDecider> $deciders
     */
    public function __construct(private readonly iterable $deciders = [])
    {
    }

    public static function withDefaultAsyncDeferral(): self
    {
        return new self([new DispatchAfterCurrentBusStampDecider(DispatchAfterCurrentBusDecider::defaults())]);
    }

    public static function withDefaultCommandDecorators(
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
    ): self {
        return self::withDefaultsFor(Command::class, $retryPolicies, $serializers, $dispatchAfter);
    }

    public static function withDefaultEventDecorators(
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
    ): self {
        return self::withDefaultsFor(Event::class, $retryPolicies, $serializers, $dispatchAfter);
    }

    public static function withoutDecorators(): self
    {
        return new self([]);
    }

    /**
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        foreach ($this->deciders as $decider) {
            $stamps = $decider->decide($message, $mode, $stamps);
        }

        return array_values($stamps);
    }

    private static function withDefaultsFor(
        string $messageType,
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
    ): self {
        $deciders = [
            new RetryPolicyStampDecider($retryPolicies, $messageType),
            new MessageSerializerStampDecider($serializers, $messageType),
        ];

        $deciders[] = new DispatchAfterCurrentBusStampDecider($dispatchAfter ?? DispatchAfterCurrentBusDecider::defaults());

        return new self($deciders);
    }
}
