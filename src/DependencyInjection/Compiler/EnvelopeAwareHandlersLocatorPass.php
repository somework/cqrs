<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Decorates the handlers locator of every CQRS bus so EnvelopeAware handlers receive the envelope.
 *
 * Runs after Symfony's MessengerPass (which registers "<bus>.messenger.handlers_locator") and on the
 * fully merged container, so bus aliases such as "messenger.default_bus" resolve to the real bus id.
 *
 * @internal
 */
final class EnvelopeAwareHandlersLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (CqrsBusIds::resolve($container) as $busId) {
            $locatorId = sprintf('%s.messenger.handlers_locator', $busId);

            if (!$container->hasDefinition($locatorId)) {
                continue;
            }

            $decoratorId = sprintf('somework_cqrs.envelope_aware_handlers_locator.%s', $busId);

            if ($container->hasDefinition($decoratorId)) {
                continue;
            }

            $container->register($decoratorId, EnvelopeAwareHandlersLocator::class)
                ->setDecoratedService($locatorId)
                ->setArguments([new Reference($decoratorId.'.inner')]);
        }
    }
}
