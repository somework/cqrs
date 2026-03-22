<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Injects causationId from CausationIdContext into the MessageMetadataStamp.
 *
 * Runs for all message types (does NOT implement MessageTypeAwareStampDecider).
 * Must be registered at priority lower than metadata deciders (125) so the
 * MessageMetadataStamp already exists in the stamps array.
 *
 * @internal
 */
final class CausationIdStampDecider implements StampDecider
{
    public function __construct(
        private readonly CausationIdContext $causationIdContext,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        $parentCorrelationId = $this->causationIdContext->current();

        if (null === $parentCorrelationId) {
            $this->logger?->debug('CausationIdStampDecider: no parent correlation ID in context, skipping');

            return $stamps;
        }

        $foundIndex = null;
        foreach ($stamps as $index => $stamp) {
            if ($stamp instanceof MessageMetadataStamp) {
                $foundIndex = $index;
                break;
            }
        }

        if (null === $foundIndex) {
            return $stamps;
        }

        /** @var MessageMetadataStamp $existingStamp */
        $existingStamp = $stamps[$foundIndex];
        unset($stamps[$foundIndex]);
        $stamps[] = $existingStamp->withCausationId($parentCorrelationId);

        $this->logger?->debug('CausationIdStampDecider: injected causationId', [
            'message' => $message::class,
            'causation_id' => $parentCorrelationId,
        ]);

        return array_values($stamps);
    }
}
