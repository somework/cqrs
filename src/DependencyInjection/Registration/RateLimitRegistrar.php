<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Support\RateLimitResolver;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** @internal */
final class RateLimitRegistrar
{
    /**
     * @param array{
     *     default?: string|null,
     *     command: array{default?: string|null, map: array<string, string>},
     *     query: array{default?: string|null, map: array<string, string>},
     *     event: array{default?: string|null, map: array<string, string>},
     * } $config
     */
    public function register(ContainerBuilder $container, array $config, ?ContainerHelper $helper = null): void
    {
        $helper ??= new ContainerHelper();

        foreach (['command', 'query', 'event'] as $type) {
            $serviceMap = [];

            $default = $config[$type]['default'] ?? null;
            $defaultPath = sprintf('rate_limiting.%s.default', $type);
            if (null === $default) {
                $default = $config['default'] ?? null;
                $defaultPath = 'rate_limiting.default';
            }
            if (null !== $default) {
                $helper->recordConfiguredService($container, $defaultPath, sprintf('limiter.%s', $default));
                $serviceMap[RateLimitResolver::DEFAULT_KEY] = new Reference(sprintf('limiter.%s', $default));
            }

            foreach ($config[$type]['map'] as $messageClass => $limiterName) {
                $helper->recordConfiguredService($container, sprintf('rate_limiting.%s.map.%s', $type, $messageClass), sprintf('limiter.%s', $limiterName));
                $serviceMap[$messageClass] = new Reference(sprintf('limiter.%s', $limiterName));
            }

            $locatorReference = ServiceLocatorTagPass::register($container, $serviceMap);

            $resolverDefinition = new Definition(RateLimitResolver::class);
            $resolverDefinition->setArgument('$limiters', $locatorReference);
            $resolverDefinition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            $resolverDefinition->setPublic(false);

            $container->setDefinition(
                sprintf('somework_cqrs.rate_limit.%s_resolver', $type),
                $resolverDefinition,
            );
        }
    }
}
