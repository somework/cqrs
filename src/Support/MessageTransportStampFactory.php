<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function sprintf;

/** @internal */
final class MessageTransportStampFactory
{
    public const TYPE_TRANSPORT_NAMES = 'transport_names';

    /**
     * @param list<string> $transports
     */
    public function create(string $type, array $transports): StampInterface
    {
        return match ($type) {
            self::TYPE_TRANSPORT_NAMES => new TransportNamesStamp($transports),
            default => throw new InvalidArgumentException(sprintf('Unknown transport stamp type "%s".', $type)),
        };
    }
}
