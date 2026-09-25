<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function get_debug_type;
use function sprintf;

/** @internal */
final class MessageMetadataProviderResolver extends AbstractMessageTypeResolver
{
    /** Key of the default for the message type (the type default, else the global one). */
    public const DEFAULT_KEY = '__somework_cqrs_metadata_default';

    public function __construct(
        ContainerInterface $providers,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($providers, $logger);
    }

    public static function withoutOverrides(?MessageMetadataProvider $defaultProvider = null): self
    {
        $provider = $defaultProvider ?? new RandomCorrelationMetadataProvider();

        return new self(new ServiceLocator([
            self::DEFAULT_KEY => static fn (): MessageMetadataProvider => $provider,
        ]));
    }

    public function resolveFor(object $message): MessageMetadataProvider
    {
        /** @var MessageMetadataProvider $provider */
        $provider = $this->resolveService($message, [self::DEFAULT_KEY]);

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
        if (!$this->hasService(self::DEFAULT_KEY)) {
            throw new \LogicException('Metadata provider resolver must be initialised with a default metadata provider.');
        }

        return $this->getService(self::DEFAULT_KEY);
    }
}
