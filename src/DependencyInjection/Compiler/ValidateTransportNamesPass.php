<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\AsMessageRouting;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function array_values;
use function class_exists;
use function class_implements;
use function class_parents;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;
use function strrpos;
use function substr;
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

        // #[AsEventHandler(fromTransport: ...)]: a worker would skip the handler for every message.
        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $id => $tags) {
            $class = $container->findDefinition($id)->getClass();
            $reflection = null === $class ? null : $container->getReflectionClass($container->getParameterBag()->resolveValue($class), false);
            if (null === $reflection) {
                continue;
            }
            foreach ($reflection->getAttributes(AsEventHandler::class) as $attribute) {
                $fromTransport = $attribute->newInstance()->fromTransport;
                if (null !== $fromTransport && !self::transportExists($container, $fromTransport)) {
                    throw new InvalidConfigurationException(sprintf('#[AsEventHandler(fromTransport: "%s")] on "%s" names a Messenger transport that is not defined.', $fromTransport, $reflection->getName()));
                }
            }
        }
    }

    /**
     * An #[Asynchronous] message needs an async bus and a transport; an #[Outbox] message needs the
     * outbox and a transport. Messages are only known through their handlers (CqrsHandlerPass
     * records them).
     */
    private function validateAsynchronousAttributes(ContainerBuilder $container): void
    {
        $metadata = $container->hasParameter('somework_cqrs.handler_metadata') ? $container->getParameter('somework_cqrs.handler_metadata') : [];
        $checked = [];

        // Queries are always handled synchronously: the attribute would be silently ignored.
        foreach (is_array($metadata) && is_array($metadata['query'] ?? null) ? $metadata['query'] : [] as $entry) {
            $messageClass = is_array($entry) ? ($entry['message'] ?? null) : null;
            $reflection = is_string($messageClass) ? $container->getReflectionClass($messageClass, false) : null;
            foreach ([Asynchronous::class, Outbox::class] as $attributeClass) {
                if (null !== $reflection && [] !== $reflection->getAttributes($attributeClass)) {
                    throw new InvalidConfigurationException(sprintf('"%s" is a query and carries #[%s]: queries are always handled synchronously. Remove the attribute.', $messageClass, self::shortName($attributeClass)));
                }
            }
        }

        foreach (['command', 'event'] as $type) {
            $entries = is_array($metadata) ? ($metadata[$type] ?? []) : [];

            foreach (is_array($entries) ? $entries : [] as $entry) {
                $messageClass = is_array($entry) ? ($entry['message'] ?? null) : null;
                if (!is_string($messageClass) || isset($checked[$messageClass])) {
                    continue;
                }
                $checked[$messageClass] = true;

                $reflection = $container->getReflectionClass($messageClass, false);
                $outbox = $reflection?->getAttributes(Outbox::class)[0] ?? null;
                $asynchronous = $reflection?->getAttributes(Asynchronous::class)[0] ?? null;
                if (null !== $outbox && null !== $asynchronous) {
                    throw new InvalidConfigurationException(sprintf('"%s" carries both #[Outbox] and #[Asynchronous]: keep one (#[Outbox] stores the message in the outbox, #[Asynchronous] sends it to a transport).', $messageClass));
                }
                $attribute = $outbox ?? $asynchronous;
                $mode = null !== $outbox ? DispatchMode::OUTBOX : DispatchMode::ASYNC;
                if (null === $attribute || self::isOverridden($container, $type, $messageClass, $mode)) {
                    continue;
                }
                $name = self::shortName($attribute->getName());

                $transport = $attribute->newInstance()->transport;

                if (null !== $transport && !self::transportExists($container, $transport)) {
                    throw new InvalidConfigurationException(sprintf('#[%s(transport: "%s")] on "%s" names a Messenger transport that is not defined.', $name, $transport, $messageClass));
                }

                if (DispatchMode::OUTBOX === $mode) {
                    if (!$container->hasDefinition('somework_cqrs.outbox.writer')) {
                        throw new InvalidConfigurationException(sprintf('"%s" carries #[Outbox], but the outbox is disabled: dispatching it would fail with OutboxNotConfiguredException. Enable "somework_cqrs.outbox".', $messageClass));
                    }
                } else {
                    $asyncBus = $container->hasParameter('somework_cqrs.bus.'.$type.'_async') ? $container->getParameter('somework_cqrs.bus.'.$type.'_async') : null;
                    if (!is_string($asyncBus) || '' === $asyncBus) {
                        throw new InvalidConfigurationException(sprintf('"%s" carries #[Asynchronous], but "somework_cqrs.buses.%s_async" is not configured: dispatching it would fail with AsyncBusNotConfiguredException.', $messageClass, $type));
                    }
                }

                if (null === $transport && !self::transportExists($container, MessageTransportStampDecider::DEFAULT_ASYNC_TRANSPORT) && !self::hasConfiguredTransport($container, $type, $messageClass) && !self::isRouted($container, $messageClass)) {
                    throw new InvalidConfigurationException(sprintf('"%s" carries #[%s] without a transport, but there is no "%s" transport, no "somework_cqrs.transports.%s_async" entry and no framework.messenger.routing route for it. Name a transport in the attribute or route the message.', $messageClass, $name, MessageTransportStampDecider::DEFAULT_ASYNC_TRANSPORT, $type));
                }
            }
        }
    }

    /**
     * An exact "dispatch_modes" map entry for another mode wins over the attribute.
     */
    private static function isOverridden(ContainerBuilder $container, string $type, string $messageClass, DispatchMode $attributeMode): bool
    {
        if (!$container->hasDefinition('somework_cqrs.dispatch_mode_decider')) {
            return false;
        }

        $map = $container->getDefinition('somework_cqrs.dispatch_mode_decider')->getArguments()['$'.$type.'Map'] ?? [];
        $mode = is_array($map) ? ($map[$messageClass] ?? null) : null;

        return $mode instanceof DispatchMode && $attributeMode !== $mode;
    }

    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    private static function hasConfiguredTransport(ContainerBuilder $container, string $type, string $messageClass): bool
    {
        $mapping = $container->hasParameter('somework_cqrs.transport_mapping') ? $container->getParameter('somework_cqrs.transport_mapping') : [];
        $section = is_array($mapping) ? ($mapping[$type.'_async'] ?? null) : null;

        if (!is_array($section)) {
            return false;
        }

        if ([] !== ($section['default'] ?? [])) {
            return true;
        }

        foreach (array_keys(is_array($section['map'] ?? null) ? $section['map'] : []) as $configuredType) {
            if (is_a($messageClass, (string) $configuredType, true)) {
                return true;
            }
        }

        return false;
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
