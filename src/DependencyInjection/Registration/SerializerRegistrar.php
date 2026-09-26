<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Support\MessageSerializerResolver;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class SerializerRegistrar
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
        $defaultId = $this->helper->configuredService($container, 'serialization.default', $config['default'], MessageSerializer::class);
        $container->setAlias('somework_cqrs.serializer.default', $defaultId)->setPublic(false);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = $config[$type]['default'];
            $resolvedTypeDefaultId = null === $typeDefaultId
                ? $defaultId
                : $this->helper->configuredService($container, sprintf('serialization.%s.default', $type), $typeDefaultId, MessageSerializer::class);

            $serviceMap = [MessageSerializerResolver::DEFAULT_KEY => new Reference($resolvedTypeDefaultId)];

            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->helper->configuredService($container, sprintf('serialization.%s.map.%s', $type, $messageClass), $serviceId, MessageSerializer::class);
                $serviceMap[$messageClass] = new Reference($resolvedId);
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.serializer.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageSerializerResolver::class);
            $resolverDefinition->setArgument('$serializers', $locatorReference);
            $resolverDefinition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.serializer.%s_resolver', $type), $resolverDefinition);
            $container->setAlias(sprintf('somework_cqrs.serializer.%s', $type), $resolvedTypeDefaultId)->setPublic(false);
        }
    }
}
