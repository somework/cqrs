<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DispatchAfterCurrentBusDecider
{
    public function __construct(
        private readonly bool $commandDefault,
        private readonly ContainerInterface $commandToggles,
        private readonly bool $eventDefault,
        private readonly ContainerInterface $eventToggles,
    ) {
    }

    public static function defaults(): self
    {
        return new self(true, new ServiceLocator([]), true, new ServiceLocator([]));
    }

    public function shouldDefer(object $message): bool
    {
        if ($message instanceof Command) {
            return $this->resolve($message, $this->commandDefault, $this->commandToggles);
        }

        if ($message instanceof Event) {
            return $this->resolve($message, $this->eventDefault, $this->eventToggles);
        }

        return false;
    }

    private function resolve(object $message, bool $default, ContainerInterface $toggles): bool
    {
        $match = MessageTypeLocator::match($toggles, $message);

        if (null === $match) {
            return $default;
        }

        return (bool) $match->service;
    }
}
