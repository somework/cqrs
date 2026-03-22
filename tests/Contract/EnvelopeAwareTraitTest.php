<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use Symfony\Component\Messenger\Envelope;

final class EnvelopeAwareTraitTest extends TestCase
{
    public function test_set_envelope_stores_envelope_instance(): void
    {
        $envelope = new Envelope(new \stdClass());
        $dummy = new DummyEnvelopeAwareObject();

        $dummy->setEnvelope($envelope);

        self::assertSame($envelope, $dummy->getEnvelope());
    }

    public function test_get_envelope_throws_when_not_set(): void
    {
        $dummy = new DummyEnvelopeAwareObject();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Messenger envelope has not been set.');

        $dummy->getEnvelope();
    }
}

final class DummyEnvelopeAwareObject implements EnvelopeAware
{
    use EnvelopeAwareTrait {
        setEnvelope as private traitSetEnvelope;
        getEnvelope as private traitGetEnvelope;
    }

    public function setEnvelope(Envelope $envelope): void
    {
        $this->traitSetEnvelope($envelope);
    }

    public function getEnvelope(): Envelope
    {
        return $this->traitGetEnvelope();
    }
}
