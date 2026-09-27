<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Validates at compile time that every command and query has at most one handler per bus,
 * counting the handlers registered for its parent classes and interfaces.
 *
 * The same handler service registered on several buses (e.g. the sync and the async command
 * bus) counts once. Messages without any handler cannot be detected here because message
 * classes are only known through their handlers; QueryBus/CommandBus report them at runtime.
 *
 * @internal
 */
final class ValidateHandlerCountPass implements CompilerPassInterface
{
    private const TYPE_LABELS = [
        'command' => 'Command',
        'query' => 'Query',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('somework_cqrs.handler_metadata')) {
            return;
        }

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');
        if (!is_array($metadata)) {
            return;
        }

        $violations = [];
        $knownBuses = self::knownBuses($container, $metadata);

        $otherRoutes = $container->hasParameter(CqrsHandlerPass::OTHER_ROUTES_PARAMETER) ? $container->getParameter(CqrsHandlerPass::OTHER_ROUTES_PARAMETER) : [];
        // Only needed here; keep it out of the compiled container.
        $container->getParameterBag()->remove(CqrsHandlerPass::OTHER_ROUTES_PARAMETER);

        foreach (self::TYPE_LABELS as $type => $label) {
            $entries = $metadata[$type] ?? [];
            if (!is_array($entries)) {
                continue;
            }

            /** @var list<array{type: string, message: string, handler_class: string, service_id: string, bus: ?string}> $entries */
            $handlers = self::byMessageAndBus($entries, $knownBuses);

            // Every handled type: Messenger also runs the handlers registered for parent classes,
            // interfaces and "*" of a message (e.g. a catch-all handler for every command).
            /** @var list<array{message: string, handler_class: string, service_id: string, bus: ?string}> $otherEntries */
            $otherEntries = is_array($otherRoutes) ? $otherRoutes : [];
            $handledTypes = self::byMessageAndBus([...$entries, ...$otherEntries], $knownBuses);

            foreach ($handlers as $messageClass => $byBus) {
                $ancestors = self::ancestorsOf($container, $messageClass);

                foreach ($byBus as $bus => $services) {
                    $inherited = [];
                    foreach ($ancestors as $ancestor) {
                        if (isset($handledTypes[$ancestor][$bus])) {
                            $services += $handledTypes[$ancestor][$bus];
                            $inherited[] = $ancestor;
                        }
                    }

                    if (count($services) < 2) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s %s has %d handlers%s: %s%s.',
                        $label,
                        $messageClass,
                        count($services),
                        '' === $bus ? '' : sprintf(' on bus "%s"', $bus),
                        implode(', ', array_keys($services)),
                        [] === $inherited ? '' : sprintf(' (including handlers of %s)', implode(', ', $inherited)),
                    );
                }
            }
        }

        if ([] !== $violations) {
            throw new \LogicException("CQRS handler validation failed (commands and queries must have exactly one handler):\n".implode("\n", $violations));
        }
    }

    /**
     * @param list<array{message: string, handler_class: string, service_id: string, bus: ?string}> $entries
     * @param list<string>                                                                          $knownBuses
     *
     * @return array<string, array<string, array<string, string>>> message => bus => service id => handler class
     */
    private static function byMessageAndBus(array $entries, array $knownBuses): array
    {
        $handlers = [];
        foreach ($entries as $entry) {
            $handlers[$entry['message']][$entry['bus'] ?? ''][$entry['service_id']] = $entry['handler_class'];
        }

        // A handler tag without bus (e.g. a plain #[AsMessageHandler]) is registered by Messenger
        // on every bus, so it competes with the handlers of each bus.
        foreach ($handlers as $messageClass => $byBus) {
            if (!isset($byBus['']) || [] === $knownBuses) {
                continue;
            }

            foreach ($knownBuses as $bus) {
                $handlers[$messageClass][$bus] = ($byBus[$bus] ?? []) + $byBus[''];
            }
            unset($handlers[$messageClass]['']);
        }

        return $handlers;
    }

    /**
     * @return list<string> Parent classes, interfaces and "*"
     */
    private static function ancestorsOf(ContainerBuilder $container, string $class): array
    {
        $reflection = $container->getReflectionClass($class, false);
        if (null === $reflection) {
            return ['*'];
        }

        $ancestors = $reflection->getInterfaceNames();
        for ($parent = $reflection->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            $ancestors[] = $parent->getName();
        }
        $ancestors[] = '*';

        return $ancestors;
    }

    /**
     * @param array<mixed> $metadata
     *
     * @return list<string>
     */
    private static function knownBuses(ContainerBuilder $container, array $metadata): array
    {
        $buses = [];
        foreach (CqrsBusIds::resolve($container) as $bus) {
            $buses[$bus] = true;
        }

        foreach ($metadata as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry) && is_string($entry['bus'] ?? null) && '' !== $entry['bus']) {
                    $buses[$entry['bus']] = true;
                }
            }
        }

        return array_keys($buses);
    }
}
