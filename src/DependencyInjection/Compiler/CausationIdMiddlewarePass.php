<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_diff;
use function array_map;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Adds CausationIdMiddleware to the CQRS buses (or to the buses listed in
 * "somework_cqrs.causation_id.buses") so that messages dispatched while a handler runs
 * inherit the handled message's correlation id and get its message id as their causation id.
 *
 * @internal
 */
final class CausationIdMiddlewarePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.causation_id';

    /** On the CQRS buses "causation_id.buses" leaves out: their handlers' messages start a new flow. */
    public const ISOLATION_MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.causation_id_isolation';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::MIDDLEWARE_ID)) {
            return;
        }

        if ($container->hasParameter('somework_cqrs.causation_id.enabled')
            && true !== $container->getParameter('somework_cqrs.causation_id.enabled')) {
            return;
        }

        $configuredBuses = $container->hasParameter('somework_cqrs.causation_id.buses')
            ? $container->getParameter('somework_cqrs.causation_id.buses')
            : [];

        $busIds = is_array($configuredBuses) && [] !== $configuredBuses
            ? $this->resolveConfiguredBuses($container, $configuredBuses)
            : CqrsBusIds::resolve($container);

        foreach ($busIds as $busId) {
            MessengerMiddlewareInjector::inject($container, $busId, self::MIDDLEWARE_ID);
        }

        // A handler on a bus without the middleware would otherwise look like the nearest outer
        // message to the messages it dispatches, and name it as their cause.
        $unlisted = array_diff(CqrsBusIds::resolve($container), $busIds);
        if ([] === $unlisted) {
            return;
        }

        $container->setDefinition(self::ISOLATION_MIDDLEWARE_ID, (new ChildDefinition(self::MIDDLEWARE_ID))->setArgument('$track', false));
        foreach ($unlisted as $busId) {
            MessengerMiddlewareInjector::inject($container, $busId, self::ISOLATION_MIDDLEWARE_ID);
        }
    }

    /**
     * @param array<mixed> $configuredBuses
     *
     * @return list<string>
     */
    private function resolveConfiguredBuses(ContainerBuilder $container, array $configuredBuses): array
    {
        return array_values(array_map(static function (mixed $busId) use ($container): string {
            if (!is_string($busId) || null === MessengerMiddlewareInjector::findBusDefinition($container, $busId)) {
                throw new InvalidConfigurationException(sprintf('"somework_cqrs.causation_id.buses" contains "%s", which is not a Messenger bus service id.', is_string($busId) ? $busId : get_debug_type($busId)));
            }

            return CqrsBusIds::resolveAlias($container, $busId);
        }, $configuredBuses));
    }
}
