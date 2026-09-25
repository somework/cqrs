<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Sets the causation id of a metadata stamp passed by the caller while another message is
 * handled: the message id of the handled message. The caller's correlation id is kept. A copy of
 * the handled message's own stamp (forwarded, e.g. with an extra added) gets a new message id.
 *
 * Stamps of the metadata providers already carry the causation (and the inherited
 * correlation id), set by MessageMetadataStampDecider; a causation id set by the caller is kept.
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
        $parent = $this->causationIdContext->current();

        if (null === $parent) {
            $this->logger?->debug('CausationIdStampDecider: no message is being handled, skipping');

            return $stamps;
        }

        // Messenger reads the last stamp of a type, so that is the one to enrich.
        $foundIndex = null;
        foreach ($stamps as $index => $stamp) {
            if ($stamp instanceof MessageMetadataStamp) {
                $foundIndex = $index;
            }
        }

        if (null === $foundIndex) {
            return $stamps;
        }

        /** @var MessageMetadataStamp $existingStamp */
        $existingStamp = $stamps[$foundIndex];

        // The handled message's own stamp, forwarded (e.g. with an extra added): the child is another
        // message of the same flow, caused by the handled one.
        if ($existingStamp->getMessageId() === $parent->getMessageId()) {
            $stamps[$foundIndex] = new MessageMetadataStamp($existingStamp->getCorrelationId(), $existingStamp->getExtras(), $parent->getMessageId());

            return array_values($stamps);
        }

        // An explicit causation id set by the caller is kept.
        if (null !== $existingStamp->getCausationId()) {
            return $stamps;
        }

        $stamps[$foundIndex] = $existingStamp->withCausationId($parent->getMessageId());

        $this->logger?->debug('CausationIdStampDecider: injected causationId', [
            'message' => $message::class,
            'causation_id' => $parent->getMessageId(),
        ]);

        return array_values($stamps);
    }
}
