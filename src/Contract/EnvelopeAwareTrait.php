<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use Symfony\Component\Messenger\Envelope;

/**
 * Provides storage for the Messenger envelope currently being handled.
 */
trait EnvelopeAwareTrait
{
    private ?Envelope $envelope = null;

    public function setEnvelope(Envelope $envelope): void
    {
        $this->envelope = $envelope;
    }

    protected function getEnvelope(): Envelope
    {
        if (!$this->envelope instanceof Envelope) {
            throw new \LogicException('Messenger envelope has not been set.');
        }

        return $this->envelope;
    }
}
