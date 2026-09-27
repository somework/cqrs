<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\AsMessageRouting;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTypeLocator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_key_exists;
use function array_values;
use function class_exists;
use function class_implements;
use function class_parents;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strrpos;
use function substr_replace;

/**
 * Fails the build when a transport named in the configuration, or by #[Asynchronous(transport: ...)]
 * on a handled message, is not a Messenger transport.
 *
 * @internal
 */
final class ValidateTransportNamesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $configuredTransportNames = $container->hasParameter('somework_cqrs.transport_names')
            ? $container->getParameter('somework_cqrs.transport_names')
            : [];

        foreach (is_array($configuredTransportNames) ? $configuredTransportNames : [] as $transportName) {
            $transportName = (string) $transportName;

            if (!self::transportExists($container, $transportName)) {
                throw new InvalidConfigurationException(sprintf('Messenger transport "%s" configured for SomeWork CQRS is not defined.', $transportName));
            }
        }

        $this->validateAsynchronousAttributes($container);
    }

    /**
     * An #[Asynchronous] message needs a transport. Messages are only known through their handlers
     * (CqrsHandlerPass records them).
     */
    private function validateAsynchronousAttributes(ContainerBuilder $container): void
    {
        $metadata = $container->hasParameter('somework_cqrs.handler_metadata') ? $container->getParameter('somework_cqrs.handler_metadata') : [];
        $checked = [];

        foreach (is_array($metadata) ? $metadata : [] as $type => $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $messageClass = is_array($entry) ? ($entry['message'] ?? null) : null;
                if (!is_string($messageClass) || isset($checked[$messageClass])) {
                    continue;
                }
                $checked[$messageClass] = true;

                $reflection = $container->getReflectionClass($messageClass, false);
                $attribute = $reflection?->getAttributes(Asynchronous::class)[0] ?? null;
                $transport = $attribute?->newInstance()->transport;

                if (null !== $transport && !self::transportExists($container, $transport)) {
                    throw new InvalidConfigurationException(sprintf('#[Asynchronous(transport: "%s")] on "%s" names a Messenger transport that is not defined.', $transport, $messageClass));
                }

                // A bare attribute falls back to the "async" transport unless the message is
                // routed. Queries are handled synchronously, an exact "dispatch_modes" sync entry
                // wins over the attribute, and without an async bus nothing is sent.
                if (null === $attribute || null !== $transport || !in_array($type, ['command', 'event'], true) || self::isForcedSynchronous($container, $type, $messageClass) || !self::hasAsyncBus($container, $type)) {
                    continue;
                }

                if (!self::transportExists($container, MessageTransportStampDecider::DEFAULT_ASYNC_TRANSPORT) && !self::hasConfiguredTransport($container, $type, $messageClass) && !self::isRouted($container, $messageClass)) {
                    throw new InvalidConfigurationException(sprintf('"%s" carries #[Asynchronous] without a transport, but there is no "%s" transport, no "somework_cqrs.transports.%s_async" entry and no framework.messenger.routing route for it. Name a transport in the attribute or route the message.', $messageClass, MessageTransportStampDecider::DEFAULT_ASYNC_TRANSPORT, $type));
                }
            }
        }
    }

    /**
     * An exact "dispatch_modes" map entry wins over the attribute.
     */
    private static function isForcedSynchronous(ContainerBuilder $container, string $type, string $messageClass): bool
    {
        if (!$container->hasDefinition('somework_cqrs.dispatch_mode_decider')) {
            return false;
        }

        $map = $container->getDefinition('somework_cqrs.dispatch_mode_decider')->getArguments()['$'.$type.'Map'] ?? [];

        return is_array($map) && DispatchMode::SYNC === ($map[$messageClass] ?? null);
    }

    private static function hasAsyncBus(ContainerBuilder $container, string $type): bool
    {
        $asyncBus = $container->hasParameter('somework_cqrs.bus.'.$type.'_async') ? $container->getParameter('somework_cqrs.bus.'.$type.'_async') : null;

        return is_string($asyncBus) && '' !== $asyncBus;
    }

    private static function hasConfiguredTransport(ContainerBuilder $container, string $type, string $messageClass): bool
    {
        $mapping = $container->hasParameter('somework_cqrs.transport_mapping') ? $container->getParameter('somework_cqrs.transport_mapping') : [];
        $section = is_array($mapping) ? ($mapping[$type.'_async'] ?? null) : null;

        if (!is_array($section)) {
            return false;
        }

        // Like MessageTransportResolver: the first entry in lookup order wins, even an empty one
        // (the decider then falls back to "async"); the default applies only without an entry.
        $map = is_array($section['map'] ?? null) ? $section['map'] : [];
        foreach (class_exists($messageClass) ? MessageTypeLocator::typesOf($messageClass) : [] as $configuredType) {
            if (array_key_exists($configuredType, $map)) {
                return [] !== $map[$configuredType];
            }
        }

        return [] !== ($section['default'] ?? []);
    }

    /**
     * Whether framework.messenger.routing routes the message, the way Messenger's senders locator
     * looks it up (class, parents, interfaces, namespace wildcards, "*"), or #[AsMessage(transport: ...)].
     */
    private static function isRouted(ContainerBuilder $container, string $messageClass): bool
    {
        if (class_exists($messageClass) && AsMessageRouting::hasTransport($messageClass)) {
            return true;
        }

        $routing = $container->hasDefinition('messenger.senders_locator') ? ($container->getDefinition('messenger.senders_locator')->getArguments()[0] ?? null) : null;

        if (!is_array($routing) || [] === $routing) {
            return false;
        }

        $parents = class_parents($messageClass);
        $interfaces = class_implements($messageClass);
        $types = [$messageClass, ...array_values(false === $parents ? [] : $parents), ...array_values(false === $interfaces ? [] : $interfaces), '*'];
        for ($wildcard = $messageClass.'\\*'; $i = strrpos($wildcard, '\\', -3);) {
            $wildcard = substr_replace($wildcard, '\\*', $i);
            $types[] = $wildcard;
        }

        foreach ($types as $routedType) {
            if (isset($routing[$routedType])) {
                return true;
            }
        }

        return false;
    }

    private static function transportExists(ContainerBuilder $container, string $transportName): bool
    {
        $transportServiceId = sprintf('messenger.transport.%s', $transportName);

        return $container->hasDefinition($transportServiceId) || $container->hasAlias($transportServiceId);
    }
}
