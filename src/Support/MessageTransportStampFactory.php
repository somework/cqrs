<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use InvalidArgumentException;
use LogicException;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function class_exists;
use function sprintf;

final class MessageTransportStampFactory
{
    public const TYPE_TRANSPORT_NAMES = 'transport_names';
    public const TYPE_SEND_MESSAGE = 'send_message';
    public const SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS = 'Symfony\\Component\\Messenger\\Stamp\\SendMessageToTransportsStamp';
    private const SEND_MESSAGE_NOT_AVAILABLE_MESSAGE = 'The "send_message" transport stamp type requires the "%s" class. Upgrade symfony/messenger to a version that provides it.';

    /**
     * @param list<string> $transports
     */
    public function create(string $type, array $transports): StampInterface
    {
        return match ($type) {
            self::TYPE_TRANSPORT_NAMES => new TransportNamesStamp($transports),
            self::TYPE_SEND_MESSAGE => $this->createSendMessageStamp($transports),
            default => throw new InvalidArgumentException(sprintf('Unknown transport stamp type "%s".', $type)),
        };
    }

    /**
     * @param list<string> $transports
     */
    private function createSendMessageStamp(array $transports): StampInterface
    {
        $class = self::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS;

        if (!class_exists($class)) {
            throw new LogicException(sprintf(self::SEND_MESSAGE_NOT_AVAILABLE_MESSAGE, $class));
        }

        return new $class($transports);
    }
}
