<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds metadata stamps for supported messages unless the caller already passed one.
 *
 * While another message is handled (with "causation_id" enabled), the provider's stamp takes
 * the correlation id of the handled message and its message id as causation id, unless the
 * provider set a causation id itself.
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
        private readonly ?CausationIdContext $causation = null,
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

        $parent = $this->causation?->current();
        if (null !== $parent && null === $metadataStamp->getCausationId()) {
            $metadataStamp = $metadataStamp
                ->withCorrelationId($parent->getCorrelationId())
                ->withCausationId($parent->getMessageId());
        }

        $stamps[] = $metadataStamp;

        return $stamps;
    }
}
