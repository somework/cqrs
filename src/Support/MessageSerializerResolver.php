<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Policy\NullMessageSerializer;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function get_debug_type;
use function sprintf;

/** @internal */
final class MessageSerializerResolver extends AbstractMessageTypeResolver
{
    /** Key of the default for the message type (the type default, else the global one). */
    public const DEFAULT_KEY = '__somework_cqrs_serializer_default';

    public function __construct(
        ContainerInterface $serializers,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($serializers, $logger);
    }

    public static function withoutOverrides(?MessageSerializer $defaultSerializer = null): self
    {
        $serializer = $defaultSerializer ?? new NullMessageSerializer();

        return new self(new ServiceLocator([
            self::DEFAULT_KEY => static fn (): MessageSerializer => $serializer,
        ]));
    }

    public function resolveFor(object $message): MessageSerializer
    {
        /** @var MessageSerializer $serializer */
        $serializer = $this->resolveService($message, [self::DEFAULT_KEY]);

        return $serializer;
    }

    protected function assertService(string $key, mixed $service): MessageSerializer
    {
        if ($service instanceof Closure) {
            $service = $service();
        }

        if (!$service instanceof MessageSerializer) {
            $message = sprintf(
                'Serializer override for "%s" must implement %s, got %s.',
                $key,
                MessageSerializer::class,
                get_debug_type($service),
            );

            throw new \LogicException($message);
        }

        return $service;
    }

    protected function resolveFallback(object $message): MessageSerializer
    {
        if (!$this->hasService(self::DEFAULT_KEY)) {
            throw new \LogicException('Serializer resolver must be initialised with a default serializer.');
        }

        return $this->getService(self::DEFAULT_KEY);
    }
}
