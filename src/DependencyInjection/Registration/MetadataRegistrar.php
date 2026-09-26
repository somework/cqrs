<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Contract\MessageMetadataProvider;
use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class MetadataRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array{
     *     default: string,
     *     command: array{default: string|null, map: array<string, string>},
     *     query: array{default: string|null, map: array<string, string>},
     *     event: array{default: string|null, map: array<string, string>},
     * } $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->helper->configuredService($container, 'metadata.default', $config['default'], MessageMetadataProvider::class);
        $container->setAlias('somework_cqrs.metadata.default', $defaultId)->setPublic(false);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = $config[$type]['default'];
            $resolvedTypeDefaultId = null === $typeDefaultId
                ? $defaultId
                : $this->helper->configuredService($container, sprintf('metadata.%s.default', $type), $typeDefaultId, MessageMetadataProvider::class);

            $serviceMap = [MessageMetadataProviderResolver::DEFAULT_KEY => new Reference($resolvedTypeDefaultId)];

            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->helper->configuredService($container, sprintf('metadata.%s.map.%s', $type, $messageClass), $serviceId, MessageMetadataProvider::class);
                $serviceMap[$messageClass] = new Reference($resolvedId);
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageMetadataProviderResolver::class);
            $resolverDefinition->setArgument('$providers', $locatorReference);
            $resolverDefinition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.metadata.%s_resolver', $type), $resolverDefinition);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s', $type), $resolvedTypeDefaultId)->setPublic(false);
        }
    }
}
