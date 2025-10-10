<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function is_a;

/**
 * Adds transport name stamps to dispatched messages based on configuration.
 */
final class MessageTransportStampDecider implements MessageTypeAwareStampDecider
{
    private const SENDERS_LOCATOR_STAMP_CLASS = 'Symfony\\Component\\Messenger\\Stamp\\SendersLocatorStamp';

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
        private readonly ?MessageTransportResolver $commandTransports,
        private readonly ?MessageTransportResolver $commandAsyncTransports,
        private readonly ?MessageTransportResolver $queryTransports,
        private readonly ?MessageTransportResolver $eventTransports,
        private readonly ?MessageTransportResolver $eventAsyncTransports,
        array $stampTypes = self::DEFAULT_STAMP_TYPES,
    ) {
        $this->stampTypes = array_replace(self::DEFAULT_STAMP_TYPES, $stampTypes);
    }

    public function messageTypes(): array
    {
        return [Command::class, Query::class, Event::class];
    }

    /**
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof TransportNamesStamp
                || is_a($stamp, self::SENDERS_LOCATOR_STAMP_CLASS, false)
                || is_a($stamp, MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS, false)) {
                return $stamps;
            }
        }

        $typeKey = $this->typeKeyFor($message, $mode);
        if (null === $typeKey) {
            return $stamps;
        }

        $resolver = $this->resolverFor($typeKey);

        if (null === $resolver) {
            return $stamps;
        }

        $transports = $resolver->resolveFor($message);

        if (null === $transports || [] === $transports) {
            return $stamps;
        }

        $stampType = $this->stampTypes[$typeKey] ?? MessageTransportStampFactory::TYPE_TRANSPORT_NAMES;

        $stamps[] = $this->stampFactory->create($stampType, $transports);

        return $stamps;
    }

    private function resolverFor(string $type): ?MessageTransportResolver
    {
        return match ($type) {
            'command' => $this->commandTransports,
            'command_async' => $this->commandAsyncTransports,
            'query' => $this->queryTransports,
            'event' => $this->eventTransports,
            'event_async' => $this->eventAsyncTransports,
            default => null,
        };
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
