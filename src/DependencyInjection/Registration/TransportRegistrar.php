<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use ArrayObject;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_unique;
use function array_values;
use function class_exists;
use function md5;
use function sprintf;

final class TransportRegistrar
{
    public function register(ContainerBuilder $container, array $config): void
    {
        $configuredTransportNames = [];
        $stampTypes = [];
        $mapping = [];

        foreach (['command', 'command_async', 'query', 'event', 'event_async'] as $type) {
            $serviceMap = [];
            $typeConfig = $config[$type];

            if (
                MessageTransportStampFactory::TYPE_SEND_MESSAGE === $typeConfig['stamp']
                && !class_exists(MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS)
            ) {
                throw new InvalidConfigurationException(sprintf('The "send_message" transport stamp type requires the "%s" class. Upgrade symfony/messenger to a version that provides it.', MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS));
            }

            $stampTypes[$type] = $typeConfig['stamp'];
            $mapping[$type] = [
                'default' => $typeConfig['default'],
                'map' => $typeConfig['map'],
            ];

            if ([] !== $typeConfig['default']) {
                $defaultServiceId = sprintf('somework_cqrs.transports.%s.default', $type);

                $defaultDefinition = new Definition(ArrayObject::class);
                $defaultDefinition->setArguments([$typeConfig['default']]);
                $defaultDefinition->setPublic(false);

                $container->setDefinition($defaultServiceId, $defaultDefinition);

                $serviceMap[MessageTransportResolver::DEFAULT_KEY] = new ServiceClosureArgument(new Reference($defaultServiceId));

                foreach ($typeConfig['default'] as $transportName) {
                    $configuredTransportNames[] = (string) $transportName;
                }
            }

            foreach ($typeConfig['map'] as $messageClass => $transports) {
                $serviceId = sprintf('somework_cqrs.transports.%s.%s', $type, md5($messageClass));

                $definition = new Definition(ArrayObject::class);
                $definition->setArguments([$transports]);
                $definition->setPublic(false);

                $container->setDefinition($serviceId, $definition);

                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($serviceId));

                foreach ($transports as $transportName) {
                    $configuredTransportNames[] = (string) $transportName;
                }
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.transports.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(MessageTransportResolver::class);
            $resolverDefinition->setArgument('$transports', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.transports.%s_resolver', $type), $resolverDefinition);
        }

        $configuredTransportNames = array_values(array_unique($configuredTransportNames));

        $container->setParameter('somework_cqrs.transport_names', $configuredTransportNames);
        $container->setParameter('somework_cqrs.transport_mapping', $mapping);
        $container->setParameter('somework_cqrs.transport_stamp_types', $stampTypes);

        $providerDefinition = new Definition(TransportMappingProvider::class);
        $providerDefinition->setArgument('$mapping', $mapping);
        $providerDefinition->setPublic(false);

        $container->setDefinition('somework_cqrs.transport_mapping_provider', $providerDefinition);
        $container->setAlias(TransportMappingProvider::class, 'somework_cqrs.transport_mapping_provider')->setPublic(false);
    }
}
