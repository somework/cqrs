<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\DependencyInjection\Registration\DoctrineEventsRegistrar;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * With "somework_cqrs.doctrine_events", fails the build when DoctrineBundle does not register the
 * Doctrine listeners of the entity managers: DoctrineBridge's RegisterEventListenersAndSubscribersPass
 * silently ignores the "doctrine.event_listener" tag without the "doctrine.connections" parameter,
 * and the recorded events would never reach the outbox.
 *
 * @internal
 */
final class DoctrineEventsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DoctrineEventsRegistrar::LISTENER_ID)) {
            return;
        }

        if ($container->hasParameter('doctrine.connections') && $container->hasParameter('doctrine.entity_managers')) {
            return;
        }

        throw new LogicException('"somework_cqrs.doctrine_events" needs DoctrineBundle with the ORM configured ("doctrine.orm"): without it, nothing registers the listener that stores the events recorded by entities, and they would never reach the outbox. Register DoctrineBundle and configure "doctrine.orm", or disable "somework_cqrs.doctrine_events".');
    }
}
