<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Support\RateLimitResolver;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
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
     *     command: array{map: array<string, string>},
     *     query: array{map: array<string, string>},
     *     event: array{map: array<string, string>},
     * } $config
     */
    public function register(ContainerBuilder $container, array $config): void
    {
        foreach (['command', 'query', 'event'] as $type) {
            $serviceMap = [];

            foreach ($config[$type]['map'] as $messageClass => $limiterName) {
                $serviceMap[$messageClass] = new ServiceClosureArgument(
                    new Reference(sprintf('limiter.%s', $limiterName)),
                );
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
