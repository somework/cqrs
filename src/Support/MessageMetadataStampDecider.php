<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds metadata stamps for supported messages unless the caller already passed one.
 *
 * @internal
 */
final class MessageMetadataStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @param class-string $messageType
     */
    public function __construct(
        private readonly MessageMetadataProviderResolver $providers,
        private readonly string $messageType,
    ) {
    }

    public function messageTypes(): array
    {
        return [$this->messageType];
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
        }

        // Metadata supplied by the caller (e.g. a propagated correlation id) wins.
        foreach ($stamps as $stamp) {
            if ($stamp instanceof MessageMetadataStamp) {
                return $stamps;
            }
        }

        $provider = $this->providers->resolveFor($message);
        $metadataStamp = $provider->getStamp($message, $mode);

        if (null === $metadataStamp) {
            return $stamps;
        }

        $stamps[] = $metadataStamp;

        return $stamps;
    }
}
