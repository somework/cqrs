<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Closure;
use Psr\Container\ContainerInterface;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function get_debug_type;
use function sprintf;

final class MessageMetadataProviderResolver extends AbstractMessageTypeResolver
{
    public const GLOBAL_DEFAULT_KEY = '__somework_cqrs_metadata_global_default';
    public const TYPE_DEFAULT_KEY = '__somework_cqrs_metadata_type_default';

    public function __construct(
        ContainerInterface $providers,
    ) {
        parent::__construct($providers);
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
        /** @var MessageMetadataProvider $provider */
        $provider = $this->resolveService($message, [self::GLOBAL_DEFAULT_KEY, self::TYPE_DEFAULT_KEY]);

        return $provider;
    }

    protected function assertService(string $key, mixed $service): MessageMetadataProvider
    {
        if ($service instanceof Closure) {
            $service = $service();
        }

        if (!$service instanceof MessageMetadataProvider) {
            $message = sprintf(
                'Metadata provider override for "%s" must implement %s, got %s.',
                $key,
                MessageMetadataProvider::class,
                get_debug_type($service),
            );

            throw new \LogicException($message);
        }

        return $service;
    }

    protected function resolveFallback(object $message): MessageMetadataProvider
    {
        $provider = $this->resolveFirstAvailable([self::TYPE_DEFAULT_KEY]);

        if (null !== $provider) {
            return $provider;
        }

        if (!$this->hasService(self::GLOBAL_DEFAULT_KEY)) {
            throw new \LogicException('Metadata provider resolver must be initialised with a global default provider.');
        }

        return $this->getService(self::GLOBAL_DEFAULT_KEY);
    }
}
