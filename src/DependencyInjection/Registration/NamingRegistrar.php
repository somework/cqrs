<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class NamingRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array{default: string, command: array{default: string|null}, query: array{default: string|null}, event: array{default: string|null}} $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        $defaultId = $this->helper->configuredService($container, 'naming.default', $config['default']);
        $serviceMap = ['default' => new Reference($defaultId)];

        foreach (['command', 'query', 'event'] as $type) {
            $typeId = $config[$type]['default'];
            $serviceMap[$type] = new Reference(null === $typeId ? $defaultId : $this->helper->configuredService($container, sprintf('naming.%s.default', $type), $typeId));
        }

        $locatorId = ServiceLocatorTagPass::register($container, $serviceMap);
        $container->setAlias('somework_cqrs.naming_locator', (string) $locatorId)->setPublic(false);
    }
}
