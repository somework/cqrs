<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
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

    public function __construct(
        private readonly MessageBusInterface $syncBus,
        private readonly ?MessageBusInterface $asyncBus = null,
        ?RetryPolicyResolver $retryPolicies = null,
        private readonly MessageSerializer $serializer = new \SomeWork\CqrsBundle\Support\NullMessageSerializer(),
    ) {
        $this->retryPolicies = $retryPolicies ?? RetryPolicyResolver::withoutOverrides();
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::SYNC, StampInterface ...$stamps): Envelope
    {
        $retryPolicy = $this->resolveRetryPolicy($event);
        $stamps = [...$stamps, ...$retryPolicy->getStamps($event, $mode)];

        $serializerStamp = $this->serializer->getStamp($event, $mode);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        return $this->selectBus($mode)->dispatch($event, $stamps);
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
