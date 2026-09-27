<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use Doctrine\ORM\Events;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

use const PHP_INT_MAX;

/**
 * Registers the listener that stores the events recorded by entities in the outbox when their
 * entity manager flushes ("somework_cqrs.doctrine_events"). CqrsExtension checks the requirements
 * (doctrine/orm, the outbox on its DBAL storage), DoctrineEventsPass that DoctrineBundle registers
 * Doctrine listeners.
 *
 * @internal
 */
final class DoctrineEventsRegistrar
{
    public const LISTENER_ID = 'somework_cqrs.doctrine_events.listener';

    /** The priority of preFlush and onFlush */
    public const LAST = -1024;

    /** The priority of postFlush */
    public const FIRST = PHP_INT_MAX;

    /**
     * @param array{connection?: string, ...} $outboxConfig The "somework_cqrs.outbox" configuration
     */
    public function register(ContainerBuilder $container, array $outboxConfig): void
    {
        $listener = new Definition(RecordedEventsListener::class);
        // Lazy: the event bus depends on the entity manager ("doctrine_transaction"), whose event manager holds the listener.
        $listener->setArgument('$publisher', new ServiceClosureArgument(new Reference(OutboxRegistrar::PUBLISHER_ID)));
        $listener->setArgument('$outboxConnection', new Reference(sprintf('doctrine.dbal.%s_connection', $outboxConfig['connection'] ?? 'default')));
        // No "connection" attribute: the listener is on every connection, so an entity manager of
        // another connection whose entities record events is refused instead of ignored. preFlush and
        // onFlush run after the other listeners (they see the entities those persist), postFlush
        // before all of them: a listener that fails, clears or flushes again cannot skip the store.
        $listener->addTag('doctrine.event_listener', ['event' => Events::preFlush, 'priority' => self::LAST]);
        $listener->addTag('doctrine.event_listener', ['event' => Events::onFlush, 'priority' => self::LAST]);
        $listener->addTag('doctrine.event_listener', ['event' => Events::postFlush, 'priority' => self::FIRST]);
        $listener->addTag('doctrine.event_listener', ['event' => Events::onClear]);
        $listener->addTag('kernel.reset', ['method' => 'reset']);
        $listener->setPublic(false);
        $container->setDefinition(self::LISTENER_ID, $listener);
    }
}
