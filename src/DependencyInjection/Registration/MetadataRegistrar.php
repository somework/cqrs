<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Support\MessageMetadataProviderResolver;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

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
        $defaultId = $this->helper->ensureServiceExists($container, $config['default']);
        $container->setAlias('somework_cqrs.metadata.default', $defaultId)->setPublic(false);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = $config[$type]['default'];
            if (null === $typeDefaultId) {
                $resolvedTypeDefaultId = $defaultId;
            } else {
                $resolvedTypeDefaultId = $this->helper->ensureServiceExists($container, $typeDefaultId);
            }

            $serviceMap = [
                MessageMetadataProviderResolver::GLOBAL_DEFAULT_KEY => new ServiceClosureArgument(new Reference($defaultId)),
                MessageMetadataProviderResolver::TYPE_DEFAULT_KEY => new ServiceClosureArgument(new Reference($resolvedTypeDefaultId)),
            ];

            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->helper->ensureServiceExists($container, $serviceId);
                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($resolvedId));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageMetadataProviderResolver::class);
            $resolverDefinition->setArgument('$providers', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.metadata.%s_resolver', $type), $resolverDefinition);
            $container->setAlias(sprintf('somework_cqrs.metadata.%s', $type), $resolvedTypeDefaultId)->setPublic(false);
        }
    }
}
