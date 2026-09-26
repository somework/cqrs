<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
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
 *  2. the transport named by #[Asynchronous(transport: ...)] or #[Outbox(transport: ...)] on
 *     asynchronous dispatches (DispatchMode::OUTBOX decides its stamps as an asynchronous dispatch);
 *  3. the transports configured for a parent class or interface, then the type default;
 *  4. for a bare #[Asynchronous] or #[Outbox] on an asynchronous dispatch, the "async" transport, unless
 *     framework.messenger.routing routes the message (then Messenger's routing applies).
 *
 * @internal
 */
final class MessageTransportStampDecider implements MessageTypeAwareStampDecider
{
    public const DEFAULT_ASYNC_TRANSPORT = 'async';

    /**
     * @var array<string, true>
     */
    private array $routedMessageTypes;

    /**
     * @var array<class-string, Asynchronous|Outbox|false>
     */
    private array $asynchronousAttributes = [];

    /**
     * @param list<string> $routedMessageTypes Keys of framework.messenger.routing: classes, interfaces, namespace wildcards and "*"
     */
    public function __construct(
        private readonly TransportResolverMap $commandResolvers,
        private readonly TransportResolverMap $queryResolvers,
        private readonly TransportResolverMap $eventResolvers,
        array $routedMessageTypes = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
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

        $transports = $this->transportsFor($message, $mode);

        if (null === $transports) {
            // Messenger handles a message that no transport is routed to right away: an async dispatch
            // that silently runs in the calling process is almost always a missing transport. (Warned
            // here, before the dispatch: inside a handler it is deferred until the handler finished.)
            if (DispatchMode::ASYNC === $mode && ($message instanceof Command || $message instanceof Event) && !$this->isRouted($message)) {
                $this->logger?->warning('{message} is dispatched asynchronously, but no transport is configured for it, so Messenger handles it synchronously. Set "somework_cqrs.transports.{type}_async", #[Asynchronous(transport: ...)] or framework.messenger.routing.', [
                    'message' => $message::class,
                    'type' => $message instanceof Event ? 'event' : 'command',
                ]);
            }

            return $stamps;
        }

        $stamps[] = new TransportNamesStamp($transports);

        return $stamps;
    }

    /**
     * The transports the bundle's configuration (or #[Asynchronous]) gives the message, or null
     * when Messenger's routing decides.
     *
     * @return non-empty-list<string>|null
     */
    public function transportsFor(object $message, DispatchMode $mode): ?array
    {
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

        return null === $transports || [] === $transports ? null : $transports;
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

    private function asynchronousAttribute(object $message): Asynchronous|Outbox|null
    {
        if (!isset($this->asynchronousAttributes[$message::class])) {
            $reflection = new \ReflectionClass($message);
            $attributes = [...$reflection->getAttributes(Outbox::class), ...$reflection->getAttributes(Asynchronous::class)];
            $this->asynchronousAttributes[$message::class] = [] === $attributes ? false : $attributes[0]->newInstance();
        }

        $attribute = $this->asynchronousAttributes[$message::class];

        return false === $attribute ? null : $attribute;
    }

    /**
     * Whether framework.messenger.routing, or #[AsMessage(transport: ...)], routes the message.
     */
    private function isRouted(object $message): bool
    {
        if (AsMessageRouting::hasTransport($message::class)) {
            return true;
        }

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
