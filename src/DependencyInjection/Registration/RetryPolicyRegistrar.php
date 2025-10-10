<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

final class RetryPolicyRegistrar
{
    public function __construct(private readonly ContainerHelper $helper)
    {
    }

    /**
     * @param array<string, array{default: string, map: array<string, string>}> $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        foreach (['command', 'query', 'event'] as $type) {
            $this->helper->registerServiceAlias($container, sprintf('somework_cqrs.retry.%s', $type), $config[$type]['default']);

            $serviceMap = [];
            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->helper->ensureServiceExists($container, $serviceId);
                $serviceMap[$messageClass] = new ServiceClosureArgument(new Reference($resolvedId));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.retry.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(RetryPolicyResolver::class);
            $resolverDefinition->setArgument('$defaultPolicy', new Reference(sprintf('somework_cqrs.retry.%s', $type)));
            $resolverDefinition->setArgument('$policies', $locatorReference);
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.retry.%s_resolver', $type), $resolverDefinition);
        }
    }
}
