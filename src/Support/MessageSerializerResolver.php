<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

final class MessageSerializerResolver
{
    public const GLOBAL_DEFAULT_KEY = '__somework_cqrs_serializer_global_default';
    public const TYPE_DEFAULT_KEY = '__somework_cqrs_serializer_type_default';

    public function __construct(
        private readonly ContainerInterface $serializers,
    ) {
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
        $match = MessageTypeLocator::match(
            $this->serializers,
            $message,
            [self::GLOBAL_DEFAULT_KEY, self::TYPE_DEFAULT_KEY],
        );

        if (null !== $match) {
            return $this->assertSerializer($match->type, $match->service);
        }

        if ($this->serializers->has(self::TYPE_DEFAULT_KEY)) {
            return $this->assertSerializer(self::TYPE_DEFAULT_KEY, $this->serializers->get(self::TYPE_DEFAULT_KEY));
        }

        if (!$this->serializers->has(self::GLOBAL_DEFAULT_KEY)) {
            throw new \LogicException('Serializer resolver must be initialised with a global default serializer.');
        }

        return $this->assertSerializer(self::GLOBAL_DEFAULT_KEY, $this->serializers->get(self::GLOBAL_DEFAULT_KEY));
    }

    private function assertSerializer(string $key, mixed $service): MessageSerializer
    {
        if ($service instanceof \Closure) {
            $service = $service();
        }

        if (!$service instanceof MessageSerializer) {
            throw new \LogicException(sprintf('Serializer override for "%s" must implement %s, got %s.', $key, MessageSerializer::class, get_debug_type($service)));
        }

        return $service;
    }
}
