<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function get_debug_type;
use function sprintf;

final class MessageMetadataProviderResolver
{
    public const GLOBAL_DEFAULT_KEY = '__somework_cqrs_metadata_global_default';
    public const TYPE_DEFAULT_KEY = '__somework_cqrs_metadata_type_default';

    public function __construct(
        private readonly ContainerInterface $providers,
    ) {
    }

    public static function withoutOverrides(?MessageMetadataProvider $defaultProvider = null): self
    {
        $provider = $defaultProvider ?? new RandomCorrelationMetadataProvider();

        return new self(new ServiceLocator([
            self::GLOBAL_DEFAULT_KEY => static fn (): MessageMetadataProvider => $provider,
            self::TYPE_DEFAULT_KEY => static fn (): MessageMetadataProvider => $provider,
        ]));
    }

    public function resolveFor(object $message): MessageMetadataProvider
    {
        $match = MessageTypeLocator::match(
            $this->providers,
            $message,
            [self::GLOBAL_DEFAULT_KEY, self::TYPE_DEFAULT_KEY],
        );

        if (null !== $match) {
            return $this->assertProvider($match->type, $match->service);
        }

        if ($this->providers->has(self::TYPE_DEFAULT_KEY)) {
            return $this->assertProvider(self::TYPE_DEFAULT_KEY, $this->providers->get(self::TYPE_DEFAULT_KEY));
        }

        if (!$this->providers->has(self::GLOBAL_DEFAULT_KEY)) {
            throw new \LogicException('Metadata provider resolver must be initialised with a global default provider.');
        }

        return $this->assertProvider(self::GLOBAL_DEFAULT_KEY, $this->providers->get(self::GLOBAL_DEFAULT_KEY));
    }

    private function assertProvider(string $key, mixed $service): MessageMetadataProvider
    {
        if ($service instanceof \Closure) {
            $service = $service();
        }

        if (!$service instanceof MessageMetadataProvider) {
            throw new \LogicException(sprintf('Metadata provider override for "%s" must implement %s, got %s.', $key, MessageMetadataProvider::class, get_debug_type($service)));
        }

        return $service;
    }
}
