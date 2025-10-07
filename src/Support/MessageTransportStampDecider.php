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
final class MessageTransportStampDecider implements StampDecider
{
    private const SENDERS_LOCATOR_STAMP_CLASS = 'Symfony\\Component\\Messenger\\Stamp\\SendersLocatorStamp';
    private const SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS = 'Symfony\\Component\\Messenger\\Stamp\\SendMessageToTransportsStamp';

    public function __construct(
        private readonly ?MessageTransportResolver $commandTransports,
        private readonly ?MessageTransportResolver $commandAsyncTransports,
        private readonly ?MessageTransportResolver $queryTransports,
        private readonly ?MessageTransportResolver $eventTransports,
        private readonly ?MessageTransportResolver $eventAsyncTransports,
    ) {
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
                || is_a($stamp, self::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS, false)) {
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

        $stamps[] = new TransportNamesStamp($transports);

        return $stamps;
    }

    private function resolverFor(object $message, DispatchMode $mode): ?MessageTransportResolver
    {
        if ($message instanceof Command) {
            return DispatchMode::ASYNC === $mode ? $this->commandAsyncTransports : $this->commandTransports;
        }

        if ($message instanceof Query) {
            return $this->queryTransports;
        }

        if ($message instanceof Event) {
            return DispatchMode::ASYNC === $mode ? $this->eventAsyncTransports : $this->eventTransports;
        }

        return null;
    }
}
