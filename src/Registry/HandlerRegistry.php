<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Registry;

use SomeWork\CqrsBundle\Contract\MessageNamingStrategy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function assert;
use function is_callable;

/**
 * Provides read access to the CQRS handler map that is compiled at container build time.
 */
final class HandlerRegistry
{
    /**
     * @var array<string, MessageNamingStrategy>
     */
    private array $namingCache = [];

    /**
     * @param array<string, list<array{type: string, message: class-string, handler_class: class-string, service_id: string, bus: string|null}>> $metadata
     */
    public function __construct(
        #[Autowire(param: 'somework_cqrs.handler_metadata')]
        private readonly array $metadata,
        #[Autowire(service: 'somework_cqrs.naming_locator')]
        private readonly ServiceLocator $namingStrategies,
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
        $key = $this->namingStrategies->has($descriptor->type)
            ? $descriptor->type
            : 'default';

        if (!isset($this->namingCache[$key])) {
            $strategy = $this->namingStrategies->get($key);

            if (is_callable($strategy)) {
                $strategy = $strategy();
            }

            assert($strategy instanceof MessageNamingStrategy);

            $this->namingCache[$key] = $strategy;
        }

        return $this->namingCache[$key]->getName($descriptor->messageClass);
    }
}
