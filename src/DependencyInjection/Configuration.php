<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_keys;
use function class_exists;
use function interface_exists;
use function is_string;
use function ltrim;
use function sprintf;
use function str_ends_with;
use function substr;
use function trim;

/** @internal */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('somework_cqrs');

        $rootNode = $treeBuilder->getRootNode();

        $children = $rootNode->children();

        $defaultBus = $children->scalarNode('default_bus');
        $defaultBus
            ->defaultNull()
            ->info('Default message bus service id to use when dispatching messages.');
        self::requireName($defaultBus, true);
        $defaultBus->end();

        $buses = $children->arrayNode('buses');
        $buses
            ->addDefaultsIfNotSet()
            ->info('Per-message-type bus service ids used by the CQRS facades.');

        $busesChildren = $buses->children();

        $commandBus = $busesChildren->scalarNode('command');
        $commandBus
            ->defaultNull()
            ->info('Synchronous command bus service id. Defaults to the Messenger default bus.');
        self::requireName($commandBus, true);
        $commandBus->end();

        $commandAsyncBus = $busesChildren->scalarNode('command_async');
        $commandAsyncBus
            ->defaultNull()
            ->info('Asynchronous command bus service id. Leave null to disable async dispatch.');
        self::requireName($commandAsyncBus, true);
        $commandAsyncBus->end();

        $queryBus = $busesChildren->scalarNode('query');
        $queryBus
            ->defaultNull()
            ->info('Query bus service id. Defaults to the Messenger default bus.');
        self::requireName($queryBus, true);
        $queryBus->end();

        $eventBus = $busesChildren->scalarNode('event');
        $eventBus
            ->defaultNull()
            ->info('Synchronous event bus service id. Defaults to the Messenger default bus.');
        self::requireName($eventBus, true);
        $eventBus->end();

        $eventAsyncBus = $busesChildren->scalarNode('event_async');
        $eventAsyncBus
            ->defaultNull()
            ->info('Asynchronous event bus service id. Leave null to disable async dispatch.');
        self::requireName($eventAsyncBus, true);
        $eventAsyncBus->end();

        $busesChildren->end();
        $buses->end();

        $naming = $children->arrayNode('naming');
        $naming
            ->addDefaultsIfNotSet()
            ->info('Message naming strategies used by diagnostics and tooling.');

        $namingChildren = $naming->children();
        self::requireName($namingChildren
            ->scalarNode('default')
            ->defaultValue(ClassNameMessageNamingStrategy::class)
            ->info('Service id implementing MessageNamingStrategy for all message types.'));
        foreach (['command' => 'commands', 'query' => 'queries', 'event' => 'events'] as $type => $label) {
            self::requireName($namingChildren
                ->scalarNode($type)
                ->defaultNull()
                ->info(sprintf('Overrides the default naming strategy for %s.', $label)), true);
        }
        $namingChildren->end();
        $naming->end();

        $retry = $children->arrayNode('retry_policies');
        $retry
            ->addDefaultsIfNotSet()
            ->info('Retry policy services applied when dispatching messages. Supports per-message overrides.');

        $retryChildren = $retry->children();
        $this->configureRetryPolicySection($retryChildren, 'command');
        $this->configureRetryPolicySection($retryChildren, 'event');
        $this->configureRetryPolicySection($retryChildren, 'query');
        $retryChildren->end();
        $retry->end();

        $retryStrategy = $children->arrayNode('retry_strategy');
        $retryStrategy
            ->addDefaultsIfNotSet()
            ->info('Transport-level retry strategy configuration using per-message RetryPolicy.');

        $retryStrategyChildren = $retryStrategy->children();

        $retryStrategyChildren
            ->arrayNode('transports')
            ->useAttributeAsKey('transport_name')
            ->normalizeKeys(false)
            ->defaultValue([])
            ->enumPrototype()
                ->values(['command', 'query', 'event'])
            ->end()
            ->info('Map of Messenger transport names to CQRS message types. Each transport will use CqrsRetryStrategy with the corresponding RetryPolicyResolver.');

        $retryStrategyChildren
            ->floatNode('jitter')
            ->defaultValue(0.0)
            ->min(0.0)
            ->max(1.0)
            ->info('Jitter factor (0.0-1.0) applied to computed retry delays to prevent thundering herd.');

        $retryStrategyChildren
            ->integerNode('max_delay')
            ->defaultValue(0)
            ->min(0)
            ->info('Maximum delay in milliseconds (0 = no cap). Prevents overflow with large multiplier/retries combinations.');

        $retryStrategyChildren->end();
        $retryStrategy->end();

        $serialization = $children->arrayNode('serialization');
        $serialization
            ->addDefaultsIfNotSet()
            ->info('MessageSerializer services that provide SerializerStamp instances.');

        $serializationChildren = $serialization->children();
        self::requireName($serializationChildren
            ->scalarNode('default')
            ->defaultValue(NullMessageSerializer::class)
            ->info('Fallback MessageSerializer service id applied to all messages.'));

        $this->configureSerializerSection($serializationChildren, 'command');
        $this->configureSerializerSection($serializationChildren, 'event');
        $this->configureSerializerSection($serializationChildren, 'query');
        $serializationChildren->end();
        $serialization->end();

        $metadata = $children->arrayNode('metadata');
        $metadata
            ->addDefaultsIfNotSet()
            ->info('MessageMetadataProvider services that supply MessageMetadataStamp instances.');

        $metadataChildren = $metadata->children();
        self::requireName($metadataChildren
            ->scalarNode('default')
            ->defaultValue(RandomCorrelationMetadataProvider::class)
            ->info('Fallback MessageMetadataProvider service id applied to all messages.'));

        $this->configureMetadataSection($metadataChildren, 'command');
        $this->configureMetadataSection($metadataChildren, 'event');
        $this->configureMetadataSection($metadataChildren, 'query');
        $metadataChildren->end();
        $metadata->end();

        $dispatchModes = $children->arrayNode('dispatch_modes');
        $dispatchModes
            ->addDefaultsIfNotSet()
            ->info('Default dispatch modes applied when no explicit mode is provided.');

        $dispatchChildren = $dispatchModes->children();
        $this->configureDispatchModeSection($dispatchChildren, 'command');
        $this->configureDispatchModeSection($dispatchChildren, 'event');
        $dispatchChildren->end();
        $dispatchModes->end();

        $transports = $children->arrayNode('transports');
        $transports
            ->addDefaultsIfNotSet()
            ->info('Messenger transports applied when dispatching messages.');

        $transportChildren = $transports->children();
        $this->configureTransportSection($transportChildren, 'command');
        $this->configureTransportSection($transportChildren, 'command_async');
        $this->configureTransportSection($transportChildren, 'query');
        $this->configureTransportSection($transportChildren, 'event');
        $this->configureTransportSection($transportChildren, 'event_async');
        $transportChildren->end();
        $transports->end();

        $async = $children->arrayNode('async');
        $async
            ->addDefaultsIfNotSet()
            ->info('Asynchronous delivery configuration.');

        $asyncChildren = $async->children();

        $dispatchAfterCurrentBus = $asyncChildren->arrayNode('dispatch_after_current_bus');
        $dispatchAfterCurrentBus
            ->addDefaultsIfNotSet()
            ->info('Controls when DispatchAfterCurrentBusStamp is added to async dispatches.');

        $dispatchAfterChildren = $dispatchAfterCurrentBus->children();
        $this->configureDispatchAfterCurrentBusSection($dispatchAfterChildren, 'command');
        $this->configureDispatchAfterCurrentBusSection($dispatchAfterChildren, 'event');
        $dispatchAfterChildren->end();
        $dispatchAfterCurrentBus->end();

        $asyncChildren->end();
        $async->end();

        $idempotency = $children->arrayNode('idempotency');
        $idempotency->addDefaultsIfNotSet()->info('Idempotency bridge configuration for DeduplicateStamp integration.');
        $idempotencyChildren = $idempotency->children();
        $idempotencyChildren->booleanNode('enabled')->defaultTrue()->info('Enable IdempotencyStamp to DeduplicateStamp bridge.');
        $idempotencyChildren->integerNode('ttl')->defaultValue(300)->min(1)->info('Default lock TTL in seconds for deduplication.');
        $idempotencyChildren->end();
        $idempotency->end();

        $causationId = $children->arrayNode('causation_id');
        $causationId->addDefaultsIfNotSet()->info('CausationIdMiddleware and CausationIdStampDecider configuration.');
        $causationIdChildren = $causationId->children();
        $causationIdChildren->booleanNode('enabled')->defaultTrue()->info('Enable CausationIdMiddleware and paired CausationIdStampDecider.');
        $causationBuses = $causationIdChildren->arrayNode('buses');
        $causationBuses->defaultValue([])->info('Limit CausationIdMiddleware to specific bus service ids. Empty array means all buses.');
        self::requireName($causationBuses->scalarPrototype());
        $causationIdChildren->end();
        $causationId->end();

        $sequence = $children->arrayNode('sequence');
        $sequence->addDefaultsIfNotSet()->info('Event sequence metadata configuration.');
        $sequenceChildren = $sequence->children();
        $sequenceChildren->booleanNode('enabled')->defaultTrue()->info('Enable AggregateSequenceStamp for SequenceAware events.');
        $sequenceChildren->end();
        $sequence->end();

        $rateLimiting = $children->arrayNode('rate_limiting');
        $rateLimiting
            ->addDefaultsIfNotSet()
            ->info('Rate limiting configuration for per-message dispatch throttling via Symfony RateLimiter.');

        $rateLimitChildren = $rateLimiting->children();
        $rateLimitChildren
            ->booleanNode('enabled')
            ->defaultTrue()
            ->info('Enable rate limiting. Inactive while no limiter is mapped; mapping a limiter requires symfony/rate-limiter.');

        $this->configureRateLimitSection($rateLimitChildren, 'command');
        $this->configureRateLimitSection($rateLimitChildren, 'query');
        $this->configureRateLimitSection($rateLimitChildren, 'event');
        $rateLimitChildren->end();
        $rateLimiting->end();

        $outbox = $children->arrayNode('outbox');
        $outbox->addDefaultsIfNotSet()->info('Transactional outbox configuration.');
        $outboxChildren = $outbox->children();
        $outboxChildren->booleanNode('enabled')->defaultFalse()
            ->info('Enable transactional outbox. Requires doctrine/dbal.');
        self::requireName($outboxChildren->scalarNode('table_name')->defaultValue('somework_cqrs_outbox')->cannotBeEmpty()
            ->info('Database table name for outbox messages.'));
        self::requireName($outboxChildren->scalarNode('connection')->defaultValue('default')->cannotBeEmpty()
            ->info('Doctrine DBAL connection name (service "doctrine.dbal.<name>_connection") holding the outbox table; use the connection of your business data.'));
        self::requireName($outboxChildren->scalarNode('serializer')->defaultValue('messenger.default_serializer')->cannotBeEmpty()
            ->info('Messenger serializer service id used by OutboxMessage::fromEnvelope() callers and by the relay to decode messages.'));
        $outboxChildren->booleanNode('auto_setup')->defaultTrue()
            ->info('Create the outbox table on first use (never inside an open transaction). Disable when the table is managed by migrations.');
        $outboxChildren->end();
        $outbox->end();

        return $treeBuilder;
    }

    private function configureRetryPolicySection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('RetryPolicy services applied to %s messages.', $type));

        $children = $node->children();
        self::requireName($children
            ->scalarNode('default')
            ->defaultValue(NullRetryPolicy::class)
            ->info(sprintf('Fallback RetryPolicy service id applied to %s messages.', $type)));

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->info('Message-specific RetryPolicy service ids, keyed by message class or interface.');
        self::requireName($map->scalarPrototype());
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureSerializerSection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('MessageSerializer services applied to %s messages.', $type));

        $children = $node->children();
        self::requireName($children
            ->scalarNode('default')
            ->defaultNull()
            ->info(sprintf('Fallback MessageSerializer service id applied to %s messages. Falls back to serialization.default when null.', $type)), true);

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->info('Message-specific MessageSerializer service ids, keyed by message class or interface.');
        self::requireName($map->scalarPrototype());
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureMetadataSection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('MessageMetadataProvider services applied to %s messages.', $type));

        $children = $node->children();
        self::requireName($children
            ->scalarNode('default')
            ->defaultNull()
            ->info(sprintf('Fallback MessageMetadataProvider service id applied to %s messages. Falls back to metadata.default when null.', $type)), true);

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->info('Message-specific MessageMetadataProvider service ids, keyed by message class or interface.');
        self::requireName($map->scalarPrototype());
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureDispatchModeSection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('Dispatch modes applied to %s messages when no explicit mode is requested.', $type));

        $children = $node->children();

        $children
            ->enumNode('default')
            ->values([DispatchMode::SYNC->value, DispatchMode::ASYNC->value])
            ->defaultValue(DispatchMode::SYNC->value)
            ->info(sprintf('Fallback dispatch mode used for %s messages.', $type));

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->scalarPrototype()
                ->validate()
                    ->ifNotInArray([DispatchMode::SYNC->value, DispatchMode::ASYNC->value])
                    ->thenInvalid('Invalid dispatch mode "%s". Expected "sync" or "async".')
                ->end()
            ->end()
            ->info(sprintf('Message-specific dispatch mode overrides for %s messages.', $type));
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureDispatchAfterCurrentBusSection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('DispatchAfterCurrentBusStamp behaviour for %s messages.', $type));

        $children = $node->children();

        $children
            ->booleanNode('default')
            ->defaultTrue()
            ->info(sprintf('Whether DispatchAfterCurrentBusStamp should be added to async %s messages when no override exists.', $type));

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->booleanPrototype()
            ->end()
            ->info(sprintf('Message-specific overrides for DispatchAfterCurrentBusStamp on async %s messages.', $type));
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureRateLimitSection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('Rate limiter mappings for %s messages.', $type));

        $children = $node->children();
        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->info('Map of message classes or interfaces to Symfony rate limiter names (as configured under framework.rate_limiter).');
        self::requireName($map->scalarPrototype());
        self::messageKeyedMap($map);

        $children->end();
        $node->end();
    }

    private function configureTransportSection(NodeBuilder $parent, string $type): void
    {
        $isAsync = str_ends_with($type, '_async');
        $baseType = $isAsync ? substr($type, 0, -6) : $type;
        $label = $isAsync ? sprintf('asynchronous %s', $baseType) : sprintf('%s', $baseType);

        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('Messenger transports applied to %s messages.', $label));

        $children = $node->children();

        $children
            ->enumNode('stamp')
            ->values(['transport_names'])
            ->defaultValue('transport_names')
            ->info(sprintf('Messenger stamp type to apply for %s messages.', $label));

        $default = $children->arrayNode('default');
        $default
            ->beforeNormalization()
                ->ifString()
                ->then(static fn (string $value): array => [$value])
            ->end()
            ->defaultValue([])
            ->info(sprintf('Default Messenger transport names for %s messages.', $label));
        self::requireName($default->scalarPrototype());
        $default->end();

        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->info(sprintf('Message-specific Messenger transport names for %s messages.', $label));
        $transportList = $map->arrayPrototype();
        $transportList
            ->beforeNormalization()
                ->ifString()
                ->then(static fn (string $value): array => [$value])
            ->end();
        self::requireName($transportList->scalarPrototype());
        self::messageKeyedMap($map);
        $map->end();

        $children->end();
        $node->end();
    }

    /**
     * Requires a non-empty string (service id or name); null is accepted when $nullable.
     */
    private static function requireName(ScalarNodeDefinition $node, bool $nullable = false): void
    {
        $node->validate()
            ->ifTrue(static fn (mixed $value): bool => !($nullable && null === $value) && (!is_string($value) || '' === trim($value)))
            ->thenInvalid($nullable ? 'Expected a non-empty string or null, got %s.' : 'Expected a non-empty string, got %s.')
        ->end();
    }

    /**
     * Keys of per-message maps are message classes or interfaces. A leading backslash is dropped,
     * and unknown names (typos, removed classes) are rejected instead of silently never matching.
     */
    private static function messageKeyedMap(ArrayNodeDefinition $map): void
    {
        $map->beforeNormalization()
            ->ifArray()
            ->then(static function (array $entries): array {
                $normalised = [];
                foreach ($entries as $key => $value) {
                    $normalised[is_string($key) ? ltrim($key, '\\') : $key] = $value;
                }

                return $normalised;
            })
        ->end();

        $map->validate()
            ->always(static function (array $entries): array {
                foreach (array_keys($entries) as $class) {
                    if (!class_exists((string) $class) && !interface_exists((string) $class)) {
                        throw new \InvalidArgumentException(sprintf('"%s" is not an existing class or interface; keys must be message class or interface names.', $class));
                    }
                }

                return $entries;
            })
        ->end();
    }
}
