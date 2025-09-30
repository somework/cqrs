<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches queries and returns the handler result.
 */
final class QueryBus
{
    private readonly RetryPolicyResolver $retryPolicies;

    public function __construct(
        private readonly MessageBusInterface $bus,
        ?RetryPolicyResolver $retryPolicies = null,
        private readonly MessageSerializer $serializer = new \SomeWork\CqrsBundle\Support\NullMessageSerializer(),
    ) {
        $this->retryPolicies = $retryPolicies ?? RetryPolicyResolver::withoutOverrides();
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $retryPolicy = $this->resolveRetryPolicy($query);
        $stamps = [...$stamps, ...$retryPolicy->getStamps($query, DispatchMode::SYNC)];

        $serializerStamp = $this->serializer->getStamp($query, DispatchMode::SYNC);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        $envelope = $this->bus->dispatch($query, $stamps);

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);
        if (!$handled instanceof HandledStamp) {
            throw new \LogicException('Query was not handled by any handler.');
        }

        return $handled->getResult();
    }

    private function resolveRetryPolicy(Query $query): RetryPolicy
    {
        return $this->retryPolicies->resolveFor($query);
    }
}
