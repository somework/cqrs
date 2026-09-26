<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Resets the services after a message the relay handled in its own process, as Messenger's
 * workers do between messages.
 *
 * "services_resetter" leaves out the services that were not instantiated: Doctrine's registry
 * ("doctrine") is not, when the handlers autowire an entity manager directly, and Messenger's
 * workers only get it through their own listeners. Resetting the registry first resets a closed
 * entity manager and clears the others (DoctrineBundle's Registry::reset()), whatever the
 * application instantiated.
 *
 * @internal
 */
final class RelayServicesResetter implements ResetInterface
{
    public function __construct(
        private readonly ?ResetInterface $services = null,
        private readonly ?object $doctrine = null,
    ) {
    }

    public function reset(): void
    {
        if ($this->doctrine instanceof ResetInterface) {
            $this->doctrine->reset();
        }

        $this->services?->reset();
    }
}
