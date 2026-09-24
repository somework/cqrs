<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function sprintf;

/** @internal */
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

        foreach ($configuredTransportNames as $transportName) {
            $transportName = (string) $transportName;
            $transportServiceId = sprintf('messenger.transport.%s', $transportName);

            if ($container->hasDefinition($transportServiceId) || $container->hasAlias($transportServiceId)) {
                continue;
            }

            throw new InvalidConfigurationException(sprintf('Messenger transport "%s" configured for SomeWork CQRS is not defined.', $transportName));
        }
    }
}
