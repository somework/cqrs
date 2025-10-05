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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function is_string;

final class CqrsHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.handler_metadata')) {
            $container->setParameter('somework_cqrs.handler_metadata', []);
        }

        $parameterBag = $container->getParameterBag();
        $metadata = [
            'command' => [],
            'query' => [],
            'event' => [],
        ];

        foreach ($container->findTaggedServiceIds('somework_cqrs.handler_interface') as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);

            if (!$definition->hasTag('messenger.message_handler')) {
                foreach ($tags as $attributes) {
                    $definition->addTag(
                        'messenger.message_handler',
                        array_filter([
                            'bus' => $attributes['bus'] ?? null,
                            'method' => $attributes['method'] ?? '__invoke',
                        ], static fn ($value): bool => null !== $value)
                    );
                }
            }

            $definition->clearTag('somework_cqrs.handler_interface');
        }

        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            $handlerClass = $this->resolveClassName($definition, $parameterBag);

            if (null === $handlerClass) {
                continue;
            }

            foreach ($tags as $attributes) {
                $messageClass = $this->resolveMessageClass($handlerClass, $attributes);
                if (null === $messageClass) {
                    continue;
                }

                $type = $this->determineType($messageClass);
                if (null === $type) {
                    continue;
                }

                $metadata[$type][] = [
                    'type' => $type,
                    'message' => $messageClass,
                    'handler_class' => $handlerClass,
                    'service_id' => $serviceId,
                    'bus' => $attributes['bus'] ?? null,
                ];
            }
        }

        $container->setParameter('somework_cqrs.handler_metadata', $metadata);
    }

    private function resolveClassName(Definition $definition, ParameterBagInterface $parameterBag): ?string
    {
        $class = $definition->getClass();
        if (null === $class) {
            if ($definition instanceof ChildDefinition) {
                $class = $definition->getClass();
            }
        }

        if (null === $class) {
            return null;
        }

        $class = $parameterBag->resolveValue($class);

        return is_string($class) ? $class : null;
    }

    /**
     * @param array{handles?: class-string, method?: string} $attributes
     */
    private function resolveMessageClass(string $handlerClass, array $attributes): ?string
    {
        if (isset($attributes['handles']) && is_string($attributes['handles'])) {
            return $attributes['handles'];
        }

        $reflection = new ReflectionClass($handlerClass);
        if (!$reflection->hasMethod('__invoke')) {
            return null;
        }

        $method = $reflection->getMethod('__invoke');
        $parameters = $method->getParameters();
        if ([] === $parameters) {
            return null;
        }

        $type = $parameters[0]->getType();
        if (null === $type) {
            return null;
        }

        foreach ($this->extractTypeNames($type) as $name) {
            if (class_exists($name) || interface_exists($name)) {
                return $name;
            }
        }

        return null;
    }

    private function determineType(string $messageClass): ?string
    {
        if (is_subclass_of($messageClass, Command::class)) {
            return 'command';
        }

        if (is_subclass_of($messageClass, Query::class)) {
            return 'query';
        }

        if (is_subclass_of($messageClass, Event::class)) {
            return 'event';
        }

        return null;
    }

    /**
     * @return list<class-string>
     */
    private function extractTypeNames(ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof ReflectionNamedType && !$innerType->isBuiltin()) {
                    $names[] = $innerType->getName();
                }
            }

            return $names;
        }

        return [];
    }
}
