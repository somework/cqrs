<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds metadata stamps for supported messages.
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
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
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
