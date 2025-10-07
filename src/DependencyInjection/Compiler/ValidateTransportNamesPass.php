<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_map;
use function in_array;
use function is_array;
use function sprintf;

final class ValidateTransportNamesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.transport_names')) {
            return;
        }

        $configuredTransportNames = $container->getParameter('somework_cqrs.transport_names');
        if (!is_array($configuredTransportNames) || [] === $configuredTransportNames) {
            return;
        }

        $knownMessengerTransportNames = [];
        if ($container->hasParameter('messenger.transport_names')) {
            $transportNamesParameter = $container->getParameter('messenger.transport_names');
            if (is_array($transportNamesParameter)) {
                $knownMessengerTransportNames = array_map(static fn ($value): string => (string) $value, $transportNamesParameter);
            }
        }

        foreach ($configuredTransportNames as $transportName) {
            $transportName = (string) $transportName;
            $transportServiceId = sprintf('messenger.transport.%s', $transportName);

            if ($container->hasDefinition($transportServiceId) || $container->hasAlias($transportServiceId)) {
                continue;
            }

            if (in_array($transportName, $knownMessengerTransportNames, true)) {
                continue;
            }

            throw new InvalidConfigurationException(sprintf('Messenger transport "%s" configured for SomeWork CQRS is not defined.', $transportName));
        }
    }
}
