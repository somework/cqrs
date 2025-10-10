<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Closure;
use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function get_debug_type;
use function sprintf;

final class MessageSerializerResolver extends AbstractMessageTypeResolver
{
    public const GLOBAL_DEFAULT_KEY = '__somework_cqrs_serializer_global_default';
    public const TYPE_DEFAULT_KEY = '__somework_cqrs_serializer_type_default';

    public function __construct(
        ContainerInterface $serializers,
    ) {
        parent::__construct($serializers);
    }

    public static function withoutOverrides(?MessageSerializer $defaultSerializer = null): self
    {
        $serializer = $defaultSerializer ?? new NullMessageSerializer();

        return new self(new ServiceLocator([
            self::GLOBAL_DEFAULT_KEY => static fn (): MessageSerializer => $serializer,
            self::TYPE_DEFAULT_KEY => static fn (): MessageSerializer => $serializer,
        ]));
    }

    public function resolveFor(object $message): MessageSerializer
    {
        /** @var MessageSerializer $serializer */
        $serializer = $this->resolveService($message, [self::GLOBAL_DEFAULT_KEY, self::TYPE_DEFAULT_KEY]);

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
        $serializer = $this->resolveFirstAvailable([self::TYPE_DEFAULT_KEY]);

        if (null !== $serializer) {
            return $serializer;
        }

        if (!$this->hasService(self::GLOBAL_DEFAULT_KEY)) {
            throw new \LogicException('Serializer resolver must be initialised with a global default serializer.');
        }

        return $this->getService(self::GLOBAL_DEFAULT_KEY);
    }
}
