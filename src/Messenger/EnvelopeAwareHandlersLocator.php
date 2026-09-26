<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use Closure;
use ReflectionFunction;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use WeakMap;

/**
 * Decorates Messenger's handlers locator to hand the current envelope to EnvelopeAware handlers.
 *
 * The original handler descriptors are yielded unchanged, so handler names (HandledStamp,
 * "already handled" detection, HandlerFailedException keys) and batch handlers keep working.
 * HandleMessageMiddleware consumes this generator lazily and invokes each handler right after
 * it is yielded, so the envelope is set immediately before the handler runs.
 *
 * @internal
 */
final class EnvelopeAwareHandlersLocator implements HandlersLocatorInterface
{
    /**
     * Handler object per handler closure (null when the handler is not EnvelopeAware).
     *
     * @var WeakMap<Closure, EnvelopeAware|null>
     */
    private WeakMap $envelopeAwareHandlers;

    /**
     * Envelope each handler object is currently handling, shared by the locators of all buses.
     *
     * @var WeakMap<EnvelopeAware, Envelope>|null
     */
    private static ?WeakMap $currentEnvelopes = null;

    public function __construct(private readonly HandlersLocatorInterface $decorated)
    {
        $this->envelopeAwareHandlers = new WeakMap();
    }

    public function getHandlers(Envelope $envelope): iterable
    {
        $currentEnvelopes = self::$currentEnvelopes ??= new WeakMap();

        foreach ($this->decorated->getHandlers($envelope) as $descriptor) {
            $handler = $this->envelopeAwareHandler($descriptor);

            if (null === $handler) {
                yield $descriptor;

                continue;
            }

            // A nested dispatch handled by the same (shared) handler service must not leave the
            // outer invocation with the inner envelope: restore it once the handler returned.
            $previous = $currentEnvelopes[$handler] ?? null;
            $handler->setEnvelope($envelope);
            $currentEnvelopes[$handler] = $envelope;

            try {
                yield $descriptor;
            } finally {
                if (null !== $previous) {
                    $handler->setEnvelope($previous);
                    $currentEnvelopes[$handler] = $previous;
                } else {
                    unset($currentEnvelopes[$handler]);
                }
            }
        }
    }

    private function envelopeAwareHandler(HandlerDescriptor $descriptor): ?EnvelopeAware
    {
        $handler = $descriptor->getHandler();

        if (!$this->envelopeAwareHandlers->offsetExists($handler)) {
            $handlerObject = (new ReflectionFunction($handler))->getClosureThis();

            $this->envelopeAwareHandlers[$handler] = $handlerObject instanceof EnvelopeAware ? $handlerObject : null;
        }

        return $this->envelopeAwareHandlers[$handler];
    }
}
