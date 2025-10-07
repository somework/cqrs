<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
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
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
    ): self {
        return self::withDefaultsFor(
            Command::class,
            $retryPolicies,
            $serializers,
            $metadata,
            $dispatchAfter,
            $transports,
            $asyncTransports,
        );
    }

    public static function withDefaultEventDecorators(
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
    ): self {
        return self::withDefaultsFor(
            Event::class,
            $retryPolicies,
            $serializers,
            $metadata,
            $dispatchAfter,
            $transports,
            $asyncTransports,
        );
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

    /**
     * @param array<string, string> $transportStampTypes
     */
    public static function withDefaultsFor(
        string $messageType,
        RetryPolicyResolver $retryPolicies,
        MessageSerializerResolver $serializers,
        MessageMetadataProviderResolver $metadata,
        ?DispatchAfterCurrentBusDecider $dispatchAfter = null,
        ?MessageTransportResolver $transports = null,
        ?MessageTransportResolver $asyncTransports = null,
        ?MessageTransportStampFactory $transportStampFactory = null,
        array $transportStampTypes = [],
    ): self {
        $transportStampFactory ??= new MessageTransportStampFactory();
        $stampTypes = array_replace(MessageTransportStampDecider::DEFAULT_STAMP_TYPES, $transportStampTypes);

        $deciders = [
            new RetryPolicyStampDecider($retryPolicies, $messageType),
            new MessageTransportStampDecider(
                $transportStampFactory,
                Command::class === $messageType ? $transports : null,
                Command::class === $messageType ? $asyncTransports : null,
                Query::class === $messageType ? $transports : null,
                Event::class === $messageType ? $transports : null,
                Event::class === $messageType ? $asyncTransports : null,
                $stampTypes,
            ),
            new MessageSerializerStampDecider($serializers, $messageType),
            new MessageMetadataStampDecider($metadata, $messageType),
        ];

        $deciders[] = new DispatchAfterCurrentBusStampDecider($dispatchAfter ?? DispatchAfterCurrentBusDecider::defaults());

        return new self($deciders);
    }
}
