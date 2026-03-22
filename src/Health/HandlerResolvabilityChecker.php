<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function sprintf;

/** @internal */
final class HandlerResolvabilityChecker implements HealthChecker
{
    public function __construct(
        private readonly HandlerRegistry $handlerRegistry,
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container,
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
            $results[] = $this->container->has($descriptor->serviceId)
                ? new CheckResult(CheckSeverity::OK, 'handler', sprintf('Handler "%s" is resolvable', $descriptor->serviceId))
                : new CheckResult(CheckSeverity::CRITICAL, 'handler', sprintf('Handler "%s" is not resolvable — service not found in container', $descriptor->serviceId));
        }

        return $results;
    }
}
