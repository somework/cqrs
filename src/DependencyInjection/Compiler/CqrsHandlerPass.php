<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function array_filter;
use function array_keys;
use function class_exists;
use function implode;
use function interface_exists;
use function is_a;
use function is_iterable;
use function is_string;
use function is_subclass_of;
use function sprintf;
use function ucfirst;

/**
 * Normalises CQRS handler tags before Symfony's MessengerPass runs.
 *
 * - converts marker-interface tags into "messenger.message_handler" tags;
 * - infers handled messages from the handler signature (union and intersection types);
 * - assigns handlers declared without an explicit bus to the configured sync bus of
 *   their type and, when configured, to the matching async bus (the worker consumes
 *   async messages on that bus);
 * - resolves bus aliases (e.g. "messenger.default_bus") to the real bus service ids;
 * - collects the handler metadata used by the registry, console commands and validation.
 *
 * @internal
 */
final class CqrsHandlerPass implements CompilerPassInterface
{
    public const INTERFACE_TAG = 'somework_cqrs.handler_interface';
    public const TYPE_ATTRIBUTE = 'somework_cqrs_type';

    private const BUS_KEYS = [
        'command' => ['command', 'command_async'],
        'query' => ['query'],
        'event' => ['event', 'event_async'],
    ];

    public function process(ContainerBuilder $container): void
    {
        $metadata = [
            'command' => [],
            'query' => [],
            'event' => [],
        ];

        $this->convertInterfaceTags($container);

        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            $handlerClass = $this->resolveClassName($definition, $container);

            if (null === $handlerClass || !class_exists($handlerClass)) {
                continue;
            }

            $normalizedTags = [];

            foreach ($tags as $attributes) {
                $declaredType = $attributes[self::TYPE_ATTRIBUTE] ?? null;
                $hasExplicitHandles = isset($attributes['handles']);
                $routes = $this->resolveRoutes($handlerClass, $attributes, null !== $declaredType);

                if ([] === $routes && null !== $declaredType && !$hasExplicitHandles) {
                    throw new InvalidArgumentException(sprintf('Cannot determine the message handled by "%s" (service "%s"). Type-hint the first parameter of %s::%s() with the message class or declare it explicitly, e.g. #[As%sHandler(%s: YourMessage::class)].', $handlerClass, $serviceId, $handlerClass, $attributes['method'] ?? '__invoke', ucfirst((string) $declaredType), (string) $declaredType));
                }

                $cqrsMessages = [];
                foreach ($routes as $messageClass) {
                    $type = $this->determineType($messageClass) ?? $declaredType;

                    if (null !== $type && isset($metadata[$type])) {
                        $cqrsMessages[$messageClass] = $type;
                    }
                }

                $buses = $this->resolveBuses($container, $attributes, $declaredType);

                foreach ($cqrsMessages as $messageClass => $type) {
                    foreach ($buses as $bus) {
                        $metadata[$type][] = [
                            'type' => $type,
                            'message' => $messageClass,
                            'handler_class' => $handlerClass,
                            'service_id' => $serviceId,
                            'bus' => $bus,
                        ];
                    }
                }

                $handles = $hasExplicitHandles || [] === $cqrsMessages ? [null] : $routes;

                foreach ($handles as $messageClass) {
                    foreach ($buses as $bus) {
                        $tag = $attributes;

                        if (null !== $messageClass) {
                            $tag['handles'] = $messageClass;
                        }

                        if (null !== $bus) {
                            $tag['bus'] = $bus;
                        }

                        $normalizedTags[] = $tag;
                    }
                }
            }

            if ($normalizedTags !== $tags) {
                $definition->clearTag('messenger.message_handler');

                foreach ($normalizedTags as $tagAttributes) {
                    $definition->addTag('messenger.message_handler', $tagAttributes);
                }
            }
        }

        $container->setParameter('somework_cqrs.handler_metadata', $metadata);
    }

    /**
     * Turns tags added through marker-interface autoconfiguration into Messenger handler tags,
     * unless the service already carries an explicit handler tag (attribute or manual tag).
     */
    private function convertInterfaceTags(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::INTERFACE_TAG) as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);

            if (!$definition->hasTag('messenger.message_handler')) {
                foreach ($tags as $attributes) {
                    $definition->addTag('messenger.message_handler', array_filter([
                        'method' => $attributes['method'] ?? '__invoke',
                        self::TYPE_ATTRIBUTE => $attributes['type'] ?? null,
                    ], static fn ($value): bool => null !== $value));
                }
            }

            $definition->clearTag(self::INTERFACE_TAG);
        }
    }

    /**
     * Buses a handler tag is registered on. Tags added by this bundle (they carry the
     * CQRS type) without an explicit bus go to the sync bus of their type and to the
     * matching async bus when one is configured; foreign tags keep Messenger's default.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<string|null>
     */
    private function resolveBuses(ContainerBuilder $container, array $attributes, ?string $type): array
    {
        if (isset($attributes['bus']) && is_string($attributes['bus']) && '' !== $attributes['bus']) {
            return [CqrsBusIds::resolveAlias($container, $attributes['bus'])];
        }

        if (null === $type || !isset(self::BUS_KEYS[$type]) || !$container->hasParameter('somework_cqrs.default_bus')) {
            return [null];
        }

        $defaultBus = $container->getParameter('somework_cqrs.default_bus');
        $buses = [];

        foreach (self::BUS_KEYS[$type] as $index => $key) {
            $parameter = 'somework_cqrs.bus.'.$key;
            $busId = $container->hasParameter($parameter) ? $container->getParameter($parameter) : null;

            if (0 === $index && !is_string($busId)) {
                $busId = $defaultBus;
            }

            if (is_string($busId) && '' !== $busId) {
                $buses[CqrsBusIds::resolveAlias($container, $busId)] = true;
            }
        }

        return [] === $buses ? [null] : array_keys($buses);
    }

    private function resolveClassName(Definition $definition, ContainerBuilder $container): ?string
    {
        $class = $definition->getClass();

        while (null === $class && $definition instanceof ChildDefinition) {
            $parentId = $definition->getParent();

            if (!$container->has($parentId)) {
                return null;
            }

            $definition = $container->findDefinition($parentId);
            $class = $definition->getClass();
        }

        if (null === $class) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($class);

        return is_string($class) ? $class : null;
    }

    /**
     * Returns the message classes a handler tag routes, either declared ("handles") or
     * inferred from the first parameter of the handler method.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    private function resolveRoutes(string $handlerClass, array $attributes, bool $isCqrsTag): array
    {
        if (isset($attributes['handles'])) {
            return $this->declaredMessages($attributes['handles']);
        }

        /** @var class-string $handlerClass */
        $reflection = new ReflectionClass($handlerClass);
        $methodName = is_string($attributes['method'] ?? null) ? $attributes['method'] : '__invoke';

        if (!$reflection->hasMethod($methodName)) {
            return [];
        }

        $parameters = $reflection->getMethod($methodName)->getParameters();
        $type = [] === $parameters ? null : $parameters[0]->getType();

        if (null === $type) {
            return [];
        }

        $routes = [];

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            $route = $this->routeFor($member, $handlerClass, $isCqrsTag);

            if (null !== $route) {
                $routes[$route] = true;
            }
        }

        return array_keys($routes);
    }

    /**
     * @return list<string>
     */
    private function declaredMessages(mixed $handles): array
    {
        if (is_string($handles)) {
            return [$handles];
        }

        if (!is_iterable($handles)) {
            return [];
        }

        $messages = [];

        foreach ($handles as $key => $handle) {
            if (is_string($key)) {
                $messages[$key] = true;
            } elseif (is_string($handle)) {
                $messages[$handle] = true;
            }
        }

        return array_keys($messages);
    }

    /**
     * Maps one member of a parameter type to the message class Messenger should route.
     *
     * An intersection "A&B" can only be routed when one of its members already satisfies all
     * the others (e.g. a class implementing the listed interfaces); routing each member
     * separately would deliver messages the handler cannot accept.
     */
    private function routeFor(ReflectionType $type, string $handlerClass, bool $isCqrsTag): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return !$type->isBuiltin() && (class_exists($name) || interface_exists($name)) ? $name : null;
        }

        if (!$type instanceof ReflectionIntersectionType) {
            return null;
        }

        $members = [];
        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType) {
                $members[] = $member->getName();
            }
        }

        foreach ($members as $candidate) {
            $satisfiesAll = true;

            foreach ($members as $member) {
                if (!is_a($candidate, $member, true)) {
                    $satisfiesAll = false;
                    break;
                }
            }

            if ($satisfiesAll) {
                return $candidate;
            }
        }

        $involvesCqrs = $isCqrsTag;
        foreach ($members as $member) {
            $involvesCqrs = $involvesCqrs
                || is_a($member, Command::class, true)
                || is_a($member, Query::class, true)
                || is_a($member, Event::class, true);
        }

        if (!$involvesCqrs) {
            return null;
        }

        throw new InvalidArgumentException(sprintf('Handler "%s" type-hints the intersection "%s", which cannot be routed by Symfony Messenger. Declare the handled message explicitly (for example with the "handles"/attribute message argument).', $handlerClass, implode('&', $members)));
    }

    private function determineType(string $messageClass): ?string
    {
        return match (true) {
            is_subclass_of($messageClass, Command::class) => 'command',
            is_subclass_of($messageClass, Query::class) => 'query',
            is_subclass_of($messageClass, Event::class) => 'event',
            default => null,
        };
    }
}
