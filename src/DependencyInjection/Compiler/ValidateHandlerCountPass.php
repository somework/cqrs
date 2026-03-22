<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_map;
use function count;
use function implode;
use function is_array;
use function sprintf;

/** @internal */
final class ValidateHandlerCountPass implements CompilerPassInterface
{
    private const VALIDATED_TYPES = ['command', 'query'];

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

        $discoveredMessages = [];
        if ($container->hasParameter('somework_cqrs.discovered_messages')) {
            $discovered = $container->getParameter('somework_cqrs.discovered_messages');
            if (is_array($discovered)) {
                $discoveredMessages = $discovered;
            }
        }

        $violations = [];

        foreach (self::VALIDATED_TYPES as $type) {
            $entries = $metadata[$type] ?? [];
            if (!is_array($entries)) {
                continue;
            }

            $label = self::TYPE_LABELS[$type];

            /** @var list<array{type: string, message: string, handler_class: string, service_id: string, bus: ?string}> $entries */
            $handlersByMessage = $this->groupHandlersByMessage($entries);

            foreach ($handlersByMessage as $messageClass => $handlers) {
                $handlerCount = count($handlers);
                if (1 !== $handlerCount) {
                    $handlerClasses = array_map(
                        static fn (array $entry): string => $entry['handler_class'],
                        $handlers,
                    );
                    $violations[] = sprintf(
                        '%s %s has %d handlers: %s.',
                        $label,
                        $messageClass,
                        $handlerCount,
                        implode(', ', $handlerClasses),
                    );
                }
            }

            $discoveredForType = $discoveredMessages[$type] ?? [];
            if (is_array($discoveredForType)) {
                foreach ($discoveredForType as $messageClass) {
                    $messageClass = (string) $messageClass;
                    if (!isset($handlersByMessage[$messageClass])) {
                        $violations[] = sprintf(
                            '%s %s has no handler registered.',
                            $label,
                            $messageClass,
                        );
                    }
                }
            }
        }

        if ([] !== $violations) {
            throw new \LogicException("CQRS handler validation failed:\n".implode("\n", $violations));
        }
    }

    /**
     * @param list<array{type: string, message: string, handler_class: string, service_id: string, bus: ?string}> $entries
     *
     * @return array<string, list<array{type: string, message: string, handler_class: string, service_id: string, bus: ?string}>>
     */
    private function groupHandlersByMessage(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[$entry['message']][] = $entry;
        }

        return $grouped;
    }
}
