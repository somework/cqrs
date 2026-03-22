<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Retry\CqrsRetryStrategy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Wires CqrsRetryStrategy into Symfony's messenger.retry_strategy_locator
 * for each transport configured in somework_cqrs.retry_strategy.transports.
 *
 * @internal
 */
final class CqrsRetryStrategyPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('messenger.retry_strategy_locator')) {
            return;
        }

        if (!$container->hasParameter('somework_cqrs.retry_strategy.transports')) {
            return;
        }

        /** @var array<string, string> $transports */
        $transports = $container->getParameter('somework_cqrs.retry_strategy.transports');

        if ([] === $transports) {
            return;
        }

        $locator = $container->getDefinition('messenger.retry_strategy_locator');

        /** @var array<string, Reference> $refs */
        $refs = $locator->getArgument(0);

        /** @var float $jitter */
        $jitter = $container->getParameter('somework_cqrs.retry_strategy.jitter');

        /** @var int $maxDelay */
        $maxDelay = $container->getParameter('somework_cqrs.retry_strategy.max_delay');

        foreach ($transports as $transportName => $messageType) {
            $resolverServiceId = sprintf('somework_cqrs.retry.%s_resolver', $messageType);

            if (!$container->hasDefinition($resolverServiceId)) {
                throw new \LogicException(sprintf('Cannot wire CqrsRetryStrategy for transport "%s": resolver service "%s" is not registered. Ensure the "%s" message type is configured under somework_cqrs.retry_policies.', $transportName, $resolverServiceId, $messageType));
            }

            $strategyDef = new Definition(CqrsRetryStrategy::class);
            $strategyDef->setArgument('$resolver', new Reference($resolverServiceId));
            $strategyDef->setArgument('$fallback', $refs[$transportName] ?? null);
            $strategyDef->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            $strategyDef->setArgument('$jitter', $jitter);
            $strategyDef->setArgument('$maxDelay', $maxDelay);

            $strategyId = sprintf('somework_cqrs.retry_strategy.%s', $transportName);
            $container->setDefinition($strategyId, $strategyDef);

            $refs[$transportName] = new Reference($strategyId);
        }

        $locator->replaceArgument(0, $refs);
    }
}
