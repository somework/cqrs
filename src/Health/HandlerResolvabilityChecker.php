<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_values;
use function sprintf;

/**
 * Instantiates every CQRS handler service to verify that it can be built at runtime
 * (missing environment variables, failing constructors, ...).
 *
 * @internal
 */
final class HandlerResolvabilityChecker implements HealthChecker
{
    /**
     * @param ServiceProviderInterface<object> $handlers handler services keyed by service id
     */
    public function __construct(
        private readonly HandlerRegistry $handlerRegistry,
        private readonly ServiceProviderInterface $handlers,
    ) {
    }

    /** @return list<CheckResult> */
    public function check(): array
    {
        $descriptors = $this->handlerRegistry->all();

        if ([] === $descriptors) {
            return [new CheckResult(
                CheckSeverity::WARNING,
                'handler',
                'No handlers registered — this may indicate a configuration issue',
            )];
        }

        $results = [];
        foreach ($descriptors as $descriptor) {
            $serviceId = $descriptor->serviceId;

            // A handler registered on several buses is checked once.
            if (isset($results[$serviceId])) {
                continue;
            }

            $results[$serviceId] = $this->checkService($serviceId);
        }

        return array_values($results);
    }

    private function checkService(string $serviceId): CheckResult
    {
        if (!$this->handlers->has($serviceId)) {
            return new CheckResult(CheckSeverity::CRITICAL, 'handler', sprintf('Handler "%s" is not resolvable — service not found in container', $serviceId));
        }

        try {
            $this->handlers->get($serviceId);
        } catch (\Throwable $exception) {
            return new CheckResult(CheckSeverity::CRITICAL, 'handler', sprintf('Handler "%s" cannot be instantiated: %s', $serviceId, $exception->getMessage()));
        }

        return new CheckResult(CheckSeverity::OK, 'handler', sprintf('Handler "%s" is resolvable', $serviceId));
    }
}
