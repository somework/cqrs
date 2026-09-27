<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_unique;
use function class_exists;
use function is_string;
use function is_subclass_of;
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
        // Every Messenger bus: handler attributes may name any bus, not only the CQRS ones.
        $busIds = array_unique([...CqrsBusIds::resolve($container), ...array_keys($container->findTaggedServiceIds('messenger.bus'))]);
        // The decorator costs time on every dispatch: only buses with an EnvelopeAware handler get it.
        $envelopeAwareBuses = self::busesWithEnvelopeAwareHandlers($container);

        foreach ($busIds as $busId) {
            if (null !== $envelopeAwareBuses && !isset($envelopeAwareBuses[$busId])) {
                continue;
            }

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

    /**
     * The buses the EnvelopeAware handlers are registered on, or null when one of them is
     * registered on every bus (a tag without a bus) or its class is unknown.
     *
     * @return array<string, true>|null
     */
    private static function busesWithEnvelopeAwareHandlers(ContainerBuilder $container): ?array
    {
        $buses = [];

        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId);

            if (!is_string($class) || !class_exists($class)) {
                return null;
            }

            if (!is_subclass_of($class, EnvelopeAware::class)) {
                continue;
            }

            foreach ($tags as $attributes) {
                $bus = $attributes['bus'] ?? null;
                if (!is_string($bus) || '' === $bus) {
                    return null;
                }

                $buses[$container->hasAlias($bus) ? (string) $container->getAlias($bus) : $bus] = true;
            }
        }

        return $buses;
    }
}
