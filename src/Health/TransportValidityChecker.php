<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Health;

use Symfony\Contracts\Service\ServiceProviderInterface;

use function array_keys;
use function sprintf;

/**
 * Instantiates every Messenger transport to verify that its DSN and options are valid.
 *
 * Most built-in transports connect on first use, so this check does not need the broker. The
 * Redis transport connects when it is created unless its "lazy" option is true: an unreachable
 * Redis server is then reported as CRITICAL, after the transport's "timeout" (unlimited by default).
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
                $results[] = new CheckResult(CheckSeverity::OK, 'transport', sprintf('Transport "%s" can be created (the connection is not tested)', $transportName));
            } catch (\Throwable $exception) {
                $results[] = new CheckResult(CheckSeverity::CRITICAL, 'transport', sprintf('Transport "%s" cannot be created: %s', $transportName, $exception->getMessage()));
            }
        }

        return $results;
    }
}
