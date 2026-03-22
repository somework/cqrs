<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function sprintf;

/** @internal */
final class TransportValidityChecker implements HealthChecker
{
    /**
     * @param list<string> $transportNames
     */
    public function __construct(
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container,
        #[Autowire(param: 'somework_cqrs.transport_names')]
        private readonly array $transportNames,
    ) {
    }

    /** @return list<CheckResult> */
    public function check(): array
    {
        if ([] === $this->transportNames) {
            return [];
        }

        $results = [];
        foreach ($this->transportNames as $transportName) {
            $serviceId = sprintf('messenger.transport.%s', $transportName);

            $results[] = $this->container->has($serviceId)
                ? new CheckResult(CheckSeverity::OK, 'transport', sprintf('Transport "%s" is valid', $transportName))
                : new CheckResult(CheckSeverity::CRITICAL, 'transport', sprintf('Transport "%s" is not valid — service "%s" not found in container', $transportName, $serviceId));
        }

        return $results;
    }
}
