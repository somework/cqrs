<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use ReflectionFunction;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * Decorates Messenger's handlers locator to inject envelopes into CQRS handlers.
 */
final class EnvelopeAwareHandlersLocator implements HandlersLocatorInterface
{
    public function __construct(private readonly HandlersLocatorInterface $decorated)
    {
    }

    public function getHandlers(Envelope $envelope): iterable
    {
        foreach ($this->decorated->getHandlers($envelope) as $descriptor) {
            yield $this->decorateDescriptor($descriptor, $envelope);
        }
    }

    private function decorateDescriptor(HandlerDescriptor $descriptor, Envelope $envelope): HandlerDescriptor
    {
        $handler = $descriptor->getHandler();
        $reflection = new ReflectionFunction($handler);
        $handlerObject = $reflection->getClosureThis();

        if (!$handlerObject instanceof EnvelopeAware) {
            return $descriptor;
        }

        $wrapper = function (...$arguments) use ($handler, $envelope) {
            \assert($this instanceof EnvelopeAware);
            $this->setEnvelope($envelope);

            return $handler(...$arguments);
        };

        $wrapper = $wrapper->bindTo($handlerObject, $handlerObject::class);

        return new HandlerDescriptor($wrapper, $descriptor->getOptions());
    }
}
