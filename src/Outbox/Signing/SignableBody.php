<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Signing;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function class_exists;
use function enum_exists;
use function interface_exists;
use function is_a;

/**
 * Decides which classes a body signed by "outbox:failed --requeue --sign" may instantiate: the
 * envelope, stamps, the message class, and the classes the declared property types of those
 * (and of their typed properties, recursively) allow. A row forged by someone without the
 * secret can then only carry the kind of objects the application stores, never an arbitrary
 * class (an unserialize() gadget).
 *
 * @internal
 */
final class SignableBody
{
    /**
     * The classes of $classes that the body may not instantiate.
     *
     * @param list<string> $classes      Every class the body instantiates
     * @param list<string> $allowedTypes Classes or interfaces the operator allows in addition (--allow-class)
     *
     * @return list<string>
     */
    public static function untrustedClasses(string $messageClass, array $classes, array $allowedTypes = []): array
    {
        if (!class_exists($messageClass)) {
            return $classes;
        }

        /** @var array<string, true> $types Classes and interfaces whose instances are allowed */
        $types = [Envelope::class => true, StampInterface::class => true, $messageClass => true];
        foreach ($allowedTypes as $type) {
            $types[$type] = true;
        }

        /** @var array<string, true> $reflected */
        $reflected = [];
        do {
            $added = false;
            foreach ($classes as $class) {
                if (isset($reflected[$class]) || !self::isAllowed($class, $types)) {
                    continue;
                }
                $reflected[$class] = true;
                foreach (self::propertyTypes($class) as $type) {
                    if (!isset($types[$type])) {
                        $types[$type] = true;
                        $added = true;
                    }
                }
            }
        } while ($added);

        return array_values(array_filter($classes, static fn (string $class): bool => !self::isAllowed($class, $types)));
    }

    /**
     * @param array<string, true> $types
     */
    private static function isAllowed(string $class, array $types): bool
    {
        if (!class_exists($class) && !enum_exists($class)) {
            return false;
        }

        foreach (array_keys($types) as $type) {
            if (is_a($class, $type, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes, interfaces and enums named by the declared types of the properties of $class
     * and its parents.
     *
     * @return list<string>
     */
    private static function propertyTypes(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $types = [];
        for ($reflection = new \ReflectionClass($class); false !== $reflection; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                foreach (self::namedTypes($property->getType()) as $type) {
                    if (!$type->isBuiltin() && (class_exists($type->getName()) || interface_exists($type->getName()) || enum_exists($type->getName()))) {
                        $types[] = $type->getName();
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @return list<\ReflectionNamedType>
     */
    private static function namedTypes(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $named = [];
            foreach ($type->getTypes() as $member) {
                $named = [...$named, ...self::namedTypes($member)];
            }

            return $named;
        }

        return [];
    }
}
