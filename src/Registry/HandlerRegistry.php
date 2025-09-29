<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Registry;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides read access to the CQRS handler map that is compiled at container build time.
 */
final class HandlerRegistry
{
    /**
     * @param array<string, list<array{type: string, message: class-string, handler_class: class-string, service_id: string, bus: string|null}>> $metadata
     */
    public function __construct(
        #[Autowire(param: 'somework_cqrs.handler_metadata')]
        private readonly array $metadata,
        #[Autowire(service: 'somework_cqrs.naming_locator')]
        private readonly ContainerInterface $namingStrategies,
    ) {
    }

    /**
     * @return list<HandlerDescriptor>
     */
    public function all(): array
    {
        $descriptors = [];
        foreach ($this->metadata as $type => $entries) {
            foreach ($entries as $entry) {
                $descriptors[] = $this->createDescriptor($type, $entry);
            }
        }

        return $descriptors;
    }

    /**
     * @return list<HandlerDescriptor>
     */
    public function byType(string $type): array
    {
        $entries = $this->metadata[$type] ?? [];
        $descriptors = [];
        foreach ($entries as $entry) {
            $descriptors[] = $this->createDescriptor($type, $entry);
        }

        return $descriptors;
    }

    private function createDescriptor(string $type, array $entry): HandlerDescriptor
    {
        return new HandlerDescriptor(
            $type,
            $entry['message'],
            $entry['handler_class'],
            $entry['service_id'],
            $entry['bus'],
        );
    }

    public function getDisplayName(HandlerDescriptor $descriptor): string
    {
        $strategy = $this->namingStrategies->has($descriptor->type)
            ? $this->namingStrategies->get($descriptor->type)
            : $this->namingStrategies->get('default');

        return $strategy->getName($descriptor->messageClass);
    }
}
