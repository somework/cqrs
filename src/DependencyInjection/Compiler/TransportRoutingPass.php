<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_keys;
use function array_values;
use function is_array;
use function is_string;

/**
 * Tells the transport stamp decider which messages framework.messenger.routing routes, so a bare
 * #[Asynchronous] does not override Messenger's routing with the default "async" transport.
 *
 * @internal
 */
final class TransportRoutingPass implements CompilerPassInterface
{
    public const DECIDER_ID = 'somework_cqrs.stamp_decider.message_transport';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::DECIDER_ID) || !$container->hasDefinition('messenger.senders_locator')) {
            return;
        }

        $routing = $container->getDefinition('messenger.senders_locator')->getArguments()[0] ?? null;
        if (!is_array($routing)) {
            return;
        }

        $routedTypes = array_values(array_filter(array_keys($routing), is_string(...)));

        $container->getDefinition(self::DECIDER_ID)->setArgument('$routedMessageTypes', $routedTypes);
    }
}
