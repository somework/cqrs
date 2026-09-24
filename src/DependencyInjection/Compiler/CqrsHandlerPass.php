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
use function array_is_list;
use function array_key_first;
use function array_keys;
use function array_values;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_iterable;
use function is_string;
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

    /** Handler routes of non-CQRS types, for ValidateHandlerCountPass (removed by it). */
    public const OTHER_ROUTES_PARAMETER = 'somework_cqrs.other_handler_routes';

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
        /** @var list<array{message: string, handler_class: string, service_id: string, bus: string|null}> $otherRoutes */
        $otherRoutes = [];

        $this->convertInterfaceTags($container);

        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $serviceId => $tags) {
            // Same rule as Messenger's MessengerPass: an option-less tag comes from autoconfiguration
            // (e.g. BatchHandlerInterface) and is ignored when the service has configured tags.
            $configuredTags = array_filter($tags, static fn (array $attributes): bool => [] !== $attributes);
            if ([] !== $configuredTags) {
                $tags = $configuredTags;
            }

            $definition = $container->findDefinition($serviceId);
            $handlerClass = $this->resolveClassName($definition, $container);

            if (null === $handlerClass || !class_exists($handlerClass)) {
                continue;
            }

            $normalizedTags = [];

            // Types declared for each method: one tag per marker interface the handler implements.
            $declaredTypes = [];
            foreach ($tags as $attributes) {
                if (isset($attributes[self::TYPE_ATTRIBUTE])) {
                    $declaredTypes[$attributes['method'] ?? '__invoke'][$attributes[self::TYPE_ATTRIBUTE]] = true;
                }
            }

            foreach ($tags as $attributes) {
                $declaredType = $attributes[self::TYPE_ATTRIBUTE] ?? null;
                $hasExplicitHandles = isset($attributes['handles']);
                $routes = $this->resolveRoutes($handlerClass, $attributes, null !== $declaredType);

                // Only the bundle's attributes and interfaces: plain Messenger tags keep Messenger's rules
                // (e.g. #[AsMessageHandler(handles: '*')]).
                if ($hasExplicitHandles && null !== $declaredType) {
                    $this->assertMethodAcceptsMessages($serviceId, $handlerClass, $attributes, $routes);
                }

                if ([] === $routes && null !== $declaredType && !$hasExplicitHandles) {
                    throw new InvalidArgumentException(sprintf('Cannot determine the message handled by "%s" (service "%s"). Type-hint the first parameter of %s::%s() with the message class or declare it explicitly, e.g. #[As%sHandler(%s: YourMessage::class)].', $handlerClass, $serviceId, $handlerClass, $attributes['method'] ?? '__invoke', ucfirst((string) $declaredType), (string) $declaredType));
                }

                $cqrsMessages = [];
                $mismatches = [];
                $coveredElsewhere = 0;
                foreach ($routes as $index => $messageClass) {
                    $messageType = $this->determineType($container, $messageClass);

                    if (null !== $declaredType && null !== $messageType && $messageType !== $declaredType) {
                        unset($routes[$index]);

                        // A handler implementing several marker interfaces (e.g. CommandHandler and
                        // EventHandler with __invoke(PlaceOrder|OrderPlaced)) gets one tag per interface:
                        // each tag keeps the members of its own type. A member no tag of the handler
                        // covers would never be handled, and an explicit attribute must match.
                        if (!$hasExplicitHandles && isset($declaredTypes[$attributes['method'] ?? '__invoke'][$messageType])) {
                            ++$coveredElsewhere;
                        } else {
                            $mismatches[$messageClass] = $messageType;
                        }

                        continue;
                    }

                    $type = $messageType ?? $declaredType;

                    if (null !== $type && isset($metadata[$type])) {
                        $cqrsMessages[$messageClass] = $type;
                    }
                }

                if ([] !== $mismatches) {
                    $messageClass = array_key_first($mismatches);
                    $messageType = $mismatches[$messageClass];

                    throw new InvalidArgumentException(sprintf('"%s" (service "%s") is registered as %s handler, but %s is %s. Use #[As%sHandler] or the %sHandler interface instead.', $handlerClass, $serviceId, self::withArticle((string) $declaredType), $messageClass, self::withArticle($messageType), ucfirst($messageType), ucfirst($messageType)));
                }

                $routes = array_values($routes);

                // Every member belongs to another interface of the handler (e.g. QueryHandler next to
                // CommandHandler and EventHandler): this tag has nothing left to route.
                if ([] === $routes && $coveredElsewhere > 0) {
                    continue;
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

                // Handlers of other types (e.g. a plain Messenger handler of an interface, or "*") also
                // run for the CQRS messages that inherit the type: ValidateHandlerCountPass counts them.
                foreach ($routes as $messageClass) {
                    if (isset($cqrsMessages[$messageClass])) {
                        continue;
                    }

                    foreach ($buses as $bus) {
                        $otherRoutes[] = ['message' => $messageClass, 'handler_class' => $handlerClass, 'service_id' => $serviceId, 'bus' => $bus];
                    }
                }

                $handles = $hasExplicitHandles || [] === $cqrsMessages ? [null] : $routes;

                foreach ($handles as $messageClass) {
                    foreach ($buses as $bus) {
                        $tag = $attributes;
                        // Internal marker, not a Messenger handler option.
                        unset($tag[self::TYPE_ATTRIBUTE]);

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
        $container->setParameter(self::OTHER_ROUTES_PARAMETER, $otherRoutes);
    }

    /**
     * Turns tags added through marker-interface autoconfiguration into Messenger handler tags,
     * unless the service already carries an explicit handler tag (attribute or manual tag).
     */
    private function convertInterfaceTags(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::INTERFACE_TAG) as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);

            // An abstract parent service is not a handler; its concrete children are tagged themselves.
            if ($definition->isAbstract()) {
                $definition->clearTag(self::INTERFACE_TAG);

                continue;
            }

            // Tags for other methods (a method-level #[AsMessageHandler]) do not cover __invoke().
            if (!self::hasInvokeHandlerTag($definition)) {
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

    private static function withArticle(string $type): string
    {
        return ('event' === $type ? 'an ' : 'a ').$type;
    }

    private static function hasInvokeHandlerTag(Definition $definition): bool
    {
        foreach ($definition->getTag('messenger.message_handler') as $attributes) {
            if ([] !== $attributes && '__invoke' === ($attributes['method'] ?? '__invoke')) {
                return true;
            }
        }

        return false;
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
     * A declared message the handler method cannot accept would fail every dispatch with a TypeError
     * (e.g. #[AsCommandHandler(ShipOrder::class)] copied onto a handler of PlaceOrder).
     *
     * @param array<string, mixed> $attributes
     * @param list<string>         $messages
     */
    private function assertMethodAcceptsMessages(string $serviceId, string $handlerClass, array $attributes, array $messages): void
    {
        // Per-message options (e.g. ['Msg' => ['method' => 'onMsg']]) may route to different methods.
        if (is_array($attributes['handles']) && !array_is_list($attributes['handles'])) {
            return;
        }

        /** @var class-string $handlerClass */
        $reflection = new ReflectionClass($handlerClass);
        $methodName = is_string($attributes['method'] ?? null) ? $attributes['method'] : '__invoke';

        if (!$reflection->hasMethod($methodName)) {
            return;
        }

        $parameters = $reflection->getMethod($methodName)->getParameters();
        $type = [] === $parameters ? null : $parameters[0]->getType();

        if (null === $type) {
            return;
        }

        foreach ($messages as $messageClass) {
            if ('*' !== $messageClass && !self::accepts($type, $messageClass)) {
                throw new InvalidArgumentException(sprintf('"%s" (service "%s") is registered for %s, but %s::%s() only accepts %s. Fix the message class of the attribute or the parameter type.', $handlerClass, $serviceId, $messageClass, $handlerClass, $methodName, (string) $type));
            }
        }
    }

    private static function accepts(ReflectionType $type, string $messageClass): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::accepts($member, $messageClass)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::accepts($member, $messageClass)) {
                    return false;
                }
            }

            return true;
        }

        if (!$type instanceof ReflectionNamedType) {
            return true;
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            return 'object' === $name || 'mixed' === $name;
        }

        return in_array($name, ['self', 'static', 'parent'], true) || is_a($messageClass, $name, true);
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

    private function determineType(ContainerBuilder $container, string $messageClass): ?string
    {
        // Through the container so a change of the message's marker interface rebuilds it in debug mode.
        $reflection = $container->getReflectionClass($messageClass, false);

        return match (true) {
            null === $reflection => null,
            $reflection->implementsInterface(Command::class) => 'command',
            $reflection->implementsInterface(Query::class) => 'query',
            $reflection->implementsInterface(Event::class) => 'event',
            default => null,
        };
    }
}
