<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Adds transport name stamps to dispatched messages based on configuration.
 *
 * @internal
 */
final class MessageTransportStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @var array<string, string>
     */
    public const DEFAULT_STAMP_TYPES = [
        'command' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES,
        'command_async' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES,
        'query' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES,
        'event' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES,
        'event_async' => MessageTransportStampFactory::TYPE_TRANSPORT_NAMES,
    ];

    /**
     * @var array<string, string>
     */
    private array $stampTypes;

    /**
     * @param array<string, string> $stampTypes
     */
    public function __construct(
        private readonly MessageTransportStampFactory $stampFactory,
        private readonly TransportResolverMap $commandResolvers,
        private readonly TransportResolverMap $queryResolvers,
        private readonly TransportResolverMap $eventResolvers,
        array $stampTypes = self::DEFAULT_STAMP_TYPES,
    ) {
        $this->stampTypes = array_replace(self::DEFAULT_STAMP_TYPES, $stampTypes);
    }

    public function messageTypes(): array
    {
        return [Command::class, Query::class, Event::class];
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                return $stamps;
            }
        }

        $resolver = $this->resolverFor($message, $mode);

        if (null === $resolver) {
            return $stamps;
        }

        $transports = $resolver->resolveFor($message);

        if (null === $transports || [] === $transports) {
            return $stamps;
        }

        $typeKey = $this->typeKeyFor($message, $mode);
        $stampType = $this->stampTypes[$typeKey] ?? MessageTransportStampFactory::TYPE_TRANSPORT_NAMES;

        $stamps[] = $this->stampFactory->create($stampType, $transports);

        return $stamps;
    }

    private function resolverFor(object $message, DispatchMode $mode): ?MessageTransportResolver
    {
        $map = match (true) {
            $message instanceof Command => $this->commandResolvers,
            $message instanceof Query => $this->queryResolvers,
            $message instanceof Event => $this->eventResolvers,
            default => null,
        };

        return $map?->resolverFor($mode);
    }

    private function typeKeyFor(object $message, DispatchMode $mode): ?string
    {
        if ($message instanceof Command) {
            return DispatchMode::ASYNC === $mode ? 'command_async' : 'command';
        }

        if ($message instanceof Query) {
            return 'query';
        }

        if ($message instanceof Event) {
            return DispatchMode::ASYNC === $mode ? 'event_async' : 'event';
        }

        return null;
    }
}
