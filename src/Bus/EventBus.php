<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches domain events through Messenger buses.
 */
final class EventBus
{
    private readonly RetryPolicyResolver $retryPolicies;
    private readonly MessageSerializerResolver $serializers;
    private readonly DispatchModeDecider $dispatchModeDecider;

    public function __construct(
        private readonly MessageBusInterface $syncBus,
        private readonly ?MessageBusInterface $asyncBus = null,
        ?RetryPolicyResolver $retryPolicies = null,
        ?MessageSerializerResolver $serializers = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
    ) {
        $this->retryPolicies = $retryPolicies ?? RetryPolicyResolver::withoutOverrides();
        $this->serializers = $serializers ?? MessageSerializerResolver::withoutOverrides();
        $this->dispatchModeDecider = $dispatchModeDecider ?? DispatchModeDecider::syncDefaults();
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($event, $mode);

        $retryPolicy = $this->resolveRetryPolicy($event);
        $stamps = [...$stamps, ...$retryPolicy->getStamps($event, $resolvedMode)];

        $serializer = $this->serializers->resolveFor($event);
        $serializerStamp = $serializer->getStamp($event, $resolvedMode);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        return $this->selectBus($resolvedMode)->dispatch($event, $stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($event, DispatchMode::SYNC, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($event, DispatchMode::ASYNC, ...$stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException('Asynchronous event bus is not configured.');
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }

    private function resolveRetryPolicy(Event $event): RetryPolicy
    {
        return $this->retryPolicies->resolveFor($event);
    }
}
