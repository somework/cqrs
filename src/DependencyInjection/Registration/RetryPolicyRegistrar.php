<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Contract\RetryPolicy;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class RetryPolicyRegistrar
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
        $defaultId = $this->helper->configuredService($container, 'retry_policies.default', $config['default'], RetryPolicy::class);

        foreach (['command', 'query', 'event'] as $type) {
            $typeDefaultId = null === $config[$type]['default']
                ? $defaultId
                : $this->helper->configuredService($container, sprintf('retry_policies.%s.default', $type), $config[$type]['default'], RetryPolicy::class);
            $container->setAlias(sprintf('somework_cqrs.retry.%s', $type), $typeDefaultId)->setPublic(false);

            $serviceMap = [];
            foreach ($config[$type]['map'] as $messageClass => $serviceId) {
                $resolvedId = $this->helper->configuredService($container, sprintf('retry_policies.%s.map.%s', $type, $messageClass), $serviceId, RetryPolicy::class);
                $serviceMap[$messageClass] = new Reference($resolvedId);
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);
            $container->setAlias(sprintf('somework_cqrs.retry.%s_locator', $type), (string) $locatorReference)->setPublic(false);

            $resolverDefinition = new Definition(RetryPolicyResolver::class);
            $resolverDefinition->setArgument('$defaultPolicy', new Reference(sprintf('somework_cqrs.retry.%s', $type)));
            $resolverDefinition->setArgument('$policies', $locatorReference);
            $resolverDefinition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            $resolverDefinition->setPublic(false);

            $container->setDefinition(sprintf('somework_cqrs.retry.%s_resolver', $type), $resolverDefinition);
        }
    }
}
