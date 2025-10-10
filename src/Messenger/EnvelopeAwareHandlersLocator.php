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
 * Decorates Messenger's handlers locator to inject envelopes into CQRS handlers.
 */
final class EnvelopeAwareHandlersLocator implements HandlersLocatorInterface
{
    /**
     * @var WeakMap<Closure, HandlerDescriptor|array{factory: Closure, options: array, reflection: ReflectionFunction}>
     */
    private WeakMap $handlerCache;

    public function __construct(private readonly HandlersLocatorInterface $decorated)
    {
        $this->handlerCache = new WeakMap();
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
        if (!$handler instanceof Closure) {
            return $descriptor;
        }

        if (isset($this->handlerCache[$handler])) {
            $cached = $this->handlerCache[$handler];

            if ($cached instanceof HandlerDescriptor) {
                return $cached;
            }

            $wrapper = $cached['factory']($envelope);

            return new HandlerDescriptor($wrapper, $cached['options']);
        }

        $reflection = new ReflectionFunction($handler);
        $handlerObject = $reflection->getClosureThis();

        if (!$handlerObject instanceof EnvelopeAware) {
            $this->handlerCache[$handler] = $descriptor;

            return $descriptor;
        }

        $options = $descriptor->getOptions();

        $factory = static function (Envelope $boundEnvelope) use ($handler, $handlerObject): Closure {
            return static function (...$arguments) use ($handler, $handlerObject, $boundEnvelope) {
                $handlerObject->setEnvelope($boundEnvelope);

                return $handler(...$arguments);
            };
        };

        $this->handlerCache[$handler] = [
            'factory' => $factory,
            'options' => $options,
            'reflection' => $reflection,
        ];

        $wrapper = $factory($envelope);

        return new HandlerDescriptor($wrapper, $options);
    }
}
