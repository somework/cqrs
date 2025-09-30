<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches commands through configured Messenger buses.
 */
final class CommandBus
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
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::SYNC, StampInterface ...$stamps): Envelope
    {
        $retryPolicy = $this->resolveRetryPolicy($command);
        $stamps = [...$stamps, ...$retryPolicy->getStamps($command, $mode)];

        $serializerStamp = $this->serializer->getStamp($command, $mode);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        return $this->selectBus($mode)->dispatch($command, $stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException('Asynchronous command bus is not configured.');
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }

    private function resolveRetryPolicy(Command $command): RetryPolicy
    {
        return $this->retryPolicies->resolveFor($command);
    }
}
