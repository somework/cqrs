<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds serializer stamps for supported messages.
 */
final class MessageSerializerStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @param class-string $messageType
     */
    public function __construct(
        private readonly MessageSerializerResolver $serializers,
        private readonly string $messageType,
    ) {
    }

    public function messageTypes(): array
    {
        return [$this->messageType];
    }

    /**
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
        }

        $serializer = $this->serializers->resolveFor($message);
        $serializerStamp = $serializer->getStamp($message, $mode);

        if (null === $serializerStamp) {
            return $stamps;
        }

        $stamps[] = $serializerStamp;

        return $stamps;
    }
}
