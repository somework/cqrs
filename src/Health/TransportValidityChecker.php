<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_keys;
use function sprintf;

/**
 * Instantiates every Messenger transport to verify that its DSN and options are valid.
 *
 * Creating a transport does not open a connection for the built-in transports, so this
 * check does not require the broker to be reachable.
 *
 * @internal
 */
final class TransportValidityChecker implements HealthChecker
{
    /**
     * @param ServiceProviderInterface<object> $transports transport services keyed by transport name
     */
    public function __construct(
        private readonly ServiceProviderInterface $transports,
    ) {
    }

    /** @return list<CheckResult> */
    public function check(): array
    {
        $results = [];
        foreach (array_keys($this->transports->getProvidedServices()) as $transportName) {
            try {
                $this->transports->get($transportName);
                $results[] = new CheckResult(CheckSeverity::OK, 'transport', sprintf('Transport "%s" is valid', $transportName));
            } catch (\Throwable $exception) {
                $results[] = new CheckResult(CheckSeverity::CRITICAL, 'transport', sprintf('Transport "%s" cannot be created: %s', $transportName, $exception->getMessage()));
            }
        }

        return $results;
    }
}
