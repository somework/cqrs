<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Adds a TransportNamesStamp to dispatched messages.
 *
 * Transports are chosen in this order (a TransportNamesStamp passed by the caller always wins):
 *  1. the transports configured for exactly the message class;
 *  2. the transport named by #[Asynchronous(transport: ...)] on asynchronous dispatches;
 *  3. the transports configured for a parent class or interface, then the type default;
 *  4. for a bare #[Asynchronous] on an asynchronous dispatch, the "async" transport, unless
 *     framework.messenger.routing routes the message (then Messenger's routing applies).
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

    public const DEFAULT_ASYNC_TRANSPORT = 'async';

    /**
     * @var array<string, true>
     */
    private array $routedMessageTypes;

    /**
     * @var array<class-string, Asynchronous|false>
     */
    private array $asynchronousAttributes = [];

    /**
     * @param array<string, string> $stampTypes
     * @param list<string>          $routedMessageTypes Keys of framework.messenger.routing: classes, interfaces, namespace wildcards and "*"
     */
    public function __construct(
        private readonly MessageTransportStampFactory $stampFactory,
        private readonly TransportResolverMap $commandResolvers,
        private readonly TransportResolverMap $queryResolvers,
        private readonly TransportResolverMap $eventResolvers,
        array $stampTypes = self::DEFAULT_STAMP_TYPES,
        array $routedMessageTypes = [],
    ) {
        $this->stampTypes = array_replace(self::DEFAULT_STAMP_TYPES, $stampTypes);
        $this->routedMessageTypes = array_fill_keys($routedMessageTypes, true);
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
        $attribute = DispatchMode::SYNC === $mode ? null : $this->asynchronousAttribute($message);

        $transports = $resolver?->resolveExactFor($message);

        if ((null === $transports || [] === $transports) && null !== $attribute?->transport) {
            $transports = [$attribute->transport];
        }

        if (null === $transports || [] === $transports) {
            $transports = $resolver?->resolveFor($message);
        }

        if ((null === $transports || [] === $transports) && null !== $attribute && !$this->isRouted($message)) {
            $transports = [self::DEFAULT_ASYNC_TRANSPORT];
        }

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

    private function asynchronousAttribute(object $message): ?Asynchronous
    {
        if (!isset($this->asynchronousAttributes[$message::class])) {
            $attributes = (new \ReflectionClass($message))->getAttributes(Asynchronous::class);
            $this->asynchronousAttributes[$message::class] = [] === $attributes ? false : $attributes[0]->newInstance();
        }

        $attribute = $this->asynchronousAttributes[$message::class];

        return false === $attribute ? null : $attribute;
    }

    /**
     * Whether framework.messenger.routing routes the message.
     */
    private function isRouted(object $message): bool
    {
        if ([] === $this->routedMessageTypes) {
            return false;
        }

        // The types Messenger's SendersLocator looks up: the class, its parents and interfaces,
        // namespace wildcards ("App\Message\*") and "*".
        foreach (HandlersLocator::listTypes(new Envelope($message)) as $type) {
            if (isset($this->routedMessageTypes[$type])) {
                return true;
            }
        }

        return false;
    }
}
