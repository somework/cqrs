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
 * Validates at compile time that every command and query has at most one handler per bus.
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

        foreach (self::TYPE_LABELS as $type => $label) {
            $entries = $metadata[$type] ?? [];
            if (!is_array($entries)) {
                continue;
            }

            /** @var array<string, array<string, array<string, string>>> $handlers message => bus => service id => handler class */
            $handlers = [];

            /** @var list<array{type: string, message: string, handler_class: string, service_id: string, bus: ?string}> $entries */
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

            foreach ($handlers as $messageClass => $byBus) {
                foreach ($byBus as $bus => $services) {
                    if (count($services) < 2) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s %s has %d handlers%s: %s.',
                        $label,
                        $messageClass,
                        count($services),
                        '' === $bus ? '' : sprintf(' on bus "%s"', $bus),
                        implode(', ', array_keys($services)),
                    );
                }
            }
        }

        if ([] !== $violations) {
            throw new \LogicException("CQRS handler validation failed (commands and queries must have exactly one handler):\n".implode("\n", $violations));
        }
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
