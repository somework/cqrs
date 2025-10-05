<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
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
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($command, $mode);

        $retryPolicy = $this->resolveRetryPolicy($command);
        $stamps = [...$stamps, ...$retryPolicy->getStamps($command, $resolvedMode)];

        $serializer = $this->serializers->resolveFor($command);
        $serializerStamp = $serializer->getStamp($command, $resolvedMode);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        return $this->selectBus($resolvedMode)->dispatch($command, $stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($command, DispatchMode::SYNC, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($command, DispatchMode::ASYNC, ...$stamps);
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
