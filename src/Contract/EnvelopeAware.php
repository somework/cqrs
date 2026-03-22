<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use Symfony\Component\Messenger\Envelope;

/**
 * Allows handlers to receive the current Messenger envelope.
 *
 * @api
 */
interface EnvelopeAware
{
    public function setEnvelope(Envelope $envelope): void;
}
