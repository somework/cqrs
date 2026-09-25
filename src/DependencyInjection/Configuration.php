<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Outbox\ReservedTableNames;
use SomeWork\CqrsBundle\Policy\ClassNameMessageNamingStrategy;
use SomeWork\CqrsBundle\Policy\NullMessageSerializer;
use SomeWork\CqrsBundle\Policy\NullRetryPolicy;
use SomeWork\CqrsBundle\Policy\RandomCorrelationMetadataProvider;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_fill_keys;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function class_exists;
use function explode;
use function interface_exists;
use function is_a;
use function is_array;
use function is_string;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function substr;
use function trim;

/** @internal */
final class Configuration implements ConfigurationInterface
{
    private const MARKERS = ['command' => Command::class, 'query' => Query::class, 'event' => Event::class];

    private const TYPES = ['command', 'query', 'event'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('somework_cqrs');

        $rootNode = $treeBuilder->getRootNode();
        self::rejectMovedOptions($rootNode);

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

        $naming->beforeNormalization()
            ->ifTrue(static fn (mixed $value): bool => is_array($value) && [] !== array_filter(array_intersect_key($value, array_flip(self::TYPES)), 'is_string'))
            ->then(static function (array $value): never {
                $type = array_key_first(array_filter(array_intersect_key($value, array_flip(self::TYPES)), 'is_string'));

                throw new InvalidConfigurationException(sprintf('"somework_cqrs.naming.%1$s" moved to "somework_cqrs.naming.%1$s.default".', $type));
            })
        ->end();

        $namingChildren = $naming->children();
        self::requireName($namingChildren
            ->scalarNode('default')
            ->defaultValue(ClassNameMessageNamingStrategy::class)
            ->info('Service id implementing MessageNamingStrategy for all message types.'));
        foreach (self::TYPES as $type) {
            $this->configureServiceSection($namingChildren, $type, 'MessageNamingStrategy', 'naming', false);
        }
        $namingChildren->end();
        $naming->end();

        $retry = $children->arrayNode('retry_policies');
        $retry
            ->addDefaultsIfNotSet()
            ->info('Retry policy services applied when dispatching messages. Supports per-message overrides.');

        $retryChildren = $retry->children();
        self::requireName($retryChildren
            ->scalarNode('default')
            ->defaultValue(NullRetryPolicy::class)
            ->info('Fallback RetryPolicy service id applied to all messages.'));
        foreach (self::TYPES as $type) {
            $this->configureServiceSection($retryChildren, $type, 'RetryPolicy', 'retry_policies');
        }
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
            ->beforeNormalization()
                // A list of transport names: each message uses the retry policies of its own type.
                ->ifTrue(static fn (mixed $value): bool => is_array($value) && [] !== $value && array_is_list($value))
                ->then(static fn (array $names): array => array_fill_keys($names, 'command'))
            ->end()
            ->defaultValue([])
            ->enumPrototype()
                ->values(['command', 'query', 'event'])
            ->end()
            ->info('Messenger transports that use CqrsRetryStrategy: a list of names, or names mapped to the retry_policies section used for messages that are neither commands, queries nor events (each CQRS message uses the section of its own type).');

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

        foreach (self::TYPES as $type) {
            $this->configureServiceSection($serializationChildren, $type, 'MessageSerializer', 'serialization');
        }
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

        foreach (self::TYPES as $type) {
            $this->configureServiceSection($metadataChildren, $type, 'MessageMetadataProvider', 'metadata');
        }
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

        $dispatchAfterCurrentBus = $children->arrayNode('dispatch_after_current_bus');
        $dispatchAfterCurrentBus
            ->addDefaultsIfNotSet()
            ->info('Controls when DispatchAfterCurrentBusStamp is added to async dispatches.');

        $dispatchAfterChildren = $dispatchAfterCurrentBus->children();
        $this->configureDispatchAfterCurrentBusSection($dispatchAfterChildren, 'command');
        $this->configureDispatchAfterCurrentBusSection($dispatchAfterChildren, 'event');
        $dispatchAfterChildren->end();
        $dispatchAfterCurrentBus->end();

        $idempotency = $children->arrayNode('idempotency');
        $idempotency->addDefaultsIfNotSet()->info('Idempotency bridge configuration for DeduplicateStamp integration.');
        $idempotencyChildren = $idempotency->children();
        $idempotencyChildren->booleanNode('enabled')->defaultTrue()->info('Enable IdempotencyStamp to DeduplicateStamp bridge.');
        // No ->min(1): Symfony 7.2 validates an env placeholder as 0 and would reject it; CqrsExtension checks literal values.
        $idempotencyChildren->integerNode('ttl')->defaultValue(300)->info('Default lock TTL in seconds for deduplication (at least 1).');
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
            ->info('Enable rate limiting. Inactive while no limiter is configured; configuring a limiter requires symfony/rate-limiter.');
        self::requireName($rateLimitChildren
            ->scalarNode('default')
            ->defaultNull()
            ->info('Rate limiter name (framework.rate_limiter) applied to every message; each message class consumes its own bucket.'), true);

        foreach (self::TYPES as $type) {
            $this->configureRateLimitSection($rateLimitChildren, $type);
        }
        $rateLimitChildren->end();
        $rateLimiting->end();

        $outbox = $children->arrayNode('outbox');
        $outbox->addDefaultsIfNotSet()->info('Transactional outbox configuration.');
        $outboxChildren = $outbox->children();
        $outboxChildren->booleanNode('enabled')->defaultFalse()
            ->info('Enable transactional outbox. Requires doctrine/dbal unless "storage" names another storage.');
        self::requireName($outboxChildren->scalarNode('storage')->defaultNull()
            ->info('Service id (or class) of the OutboxStorage; null for the DBAL storage configured by table_name, connection and auto_setup. The setup, failed and health features need it to implement OutboxSchema, FailedOutboxMessages and OutboxMonitoring.'), true);
        $tableName = $outboxChildren->scalarNode('table_name')->defaultValue('somework_cqrs_outbox')->cannotBeEmpty()
            ->info('Database table name for outbox messages (letters, digits and underscores, optionally "schema.table"; not a reserved SQL word).');
        self::requireName($tableName);
        $tableName->validate()
            ->ifTrue(static fn (mixed $value): bool => is_string($value) && 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $value))
            ->thenInvalid('Invalid outbox table name %s: use letters, digits and underscores, optionally prefixed with a schema ("schema.table").')
        ->end();
        // The outbox queries do not quote the name, so a reserved word breaks them (e.g. "order", or "user" on PostgreSQL).
        $tableName->validate()
            ->ifTrue(static function (mixed $value): bool {
                foreach (is_string($value) ? explode('.', $value) : [] as $part) {
                    if (ReservedTableNames::isReserved($part)) {
                        return true;
                    }
                }

                return false;
            })
            ->thenInvalid('Invalid outbox table name %s: it is a reserved SQL word in MySQL, MariaDB, PostgreSQL or SQLite. Choose another name, e.g. "somework_cqrs_outbox".')
        ->end();
        self::requireName($outboxChildren->scalarNode('connection')->defaultValue('default')->cannotBeEmpty()
            ->info('Doctrine DBAL connection name (service "doctrine.dbal.<name>_connection") holding the outbox table; use the connection of your business data.'));
        self::requireName($outboxChildren->scalarNode('serializer')->defaultValue('messenger.default_serializer')->cannotBeEmpty()
            ->info('Messenger serializer service id used by OutboxMessage::fromEnvelope() callers and by the relay to decode messages.'));
        $outboxChildren->booleanNode('auto_setup')->defaultTrue()
            ->info('Create the outbox table, or add missing columns, on first use (never inside an open transaction). Disable when the table is managed by migrations.');
        // No ->min(1): Symfony 7.2 validates an env placeholder as 0 and would reject it; CqrsExtension checks literal values.
        $outboxChildren->integerNode('max_attempts')->defaultValue(10)
            ->info('Attempts after which the relay gives up on a message that fails to decode or send (at least 1); three times as many when its transport fails. Retries wait 1 minute, doubling up to 1 hour; see "somework:cqrs:outbox:failed".');
        $signing = $outboxChildren->arrayNode('signing');
        $signing->addDefaultsIfNotSet()
            ->info('HMAC-SHA256 signatures of stored rows: the relay only decodes (unserializes) rows this application signed.');
        $signingChildren = $signing->children();
        $signingChildren->booleanNode('enabled')->defaultTrue()
            ->info('Sign every stored row and verify it before relaying it (no environment variables).');
        self::requireName($signingChildren->scalarNode('secret')->defaultNull()
            ->info('Secret of the signatures; null uses kernel.secret ("framework.secret"). Environment variables are allowed.'), true);
        $signingChildren->arrayNode('previous_secrets')
            ->info('Secrets whose signatures are still accepted, e.g. the old secret after a rotation, until the rows signed with it are relayed.')
            ->scalarPrototype()->end()
            ->defaultValue([]);
        $signingChildren->booleanNode('accept_unsigned')->defaultFalse()
            ->info('Relay rows without a signature (stored before signing was enabled) while they drain. Rows with a wrong signature are always given up.');
        $signingChildren->end();
        $signing->end();
        $outboxChildren->end();
        $outbox->end();

        return $treeBuilder;
    }

    /**
     * The shape shared by the per-message service sections: a per-type default that falls back
     * to the section's global default, and a map of message classes or interfaces to service ids.
     */
    private function configureServiceSection(NodeBuilder $parent, string $type, string $contract, string $section, bool $withMap = true): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('%s services applied to %s messages.', $contract, $type));

        $children = $node->children();
        self::requireName($children
            ->scalarNode('default')
            ->defaultNull()
            ->info(sprintf('%s service id applied to %s messages. Falls back to %s.default when null.', $contract, $type, $section)), true);

        if ($withMap) {
            $map = $children->arrayNode('map');
            $map
                ->useAttributeAsKey('message')
                ->defaultValue([])
                ->info(sprintf('Message-specific %s service ids, keyed by message class or interface.', $contract));
            self::requireName($map->scalarPrototype());
            self::messageKeyedMap($map, $type);
        }

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
                    ->thenInvalid('Invalid dispatch mode %s. Expected "sync" or "async".')
                ->end()
            ->end()
            ->info(sprintf('Message-specific dispatch mode overrides for %s messages.', $type));
        self::messageKeyedMap($map, $type);

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
        self::messageKeyedMap($map, $type);

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
        self::requireName($children
            ->scalarNode('default')
            ->defaultNull()
            ->info(sprintf('Rate limiter name applied to %s messages. Falls back to rate_limiting.default when null.', $type)), true);
        $map = $children->arrayNode('map');
        $map
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->info('Map of message classes or interfaces to Symfony rate limiter names (as configured under framework.rate_limiter).');
        self::requireName($map->scalarPrototype());
        self::messageKeyedMap($map, $type);

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

        $node->beforeNormalization()
            ->ifTrue(static fn (mixed $value): bool => is_array($value) && array_key_exists('stamp', $value))
            ->then(static function () use ($type): never {
                throw new InvalidConfigurationException(sprintf('"somework_cqrs.transports.%s.stamp" was removed: the transports are always applied with a TransportNamesStamp.', $type));
            })
        ->end();

        $children = $node->children();

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
        self::messageKeyedMap($map, $baseType);
        $map->end();

        $children->end();
        $node->end();
    }

    /**
     * Options of earlier versions fail with where they moved, instead of "Unrecognized option".
     */
    private static function rejectMovedOptions(ArrayNodeDefinition $root): void
    {
        $root->beforeNormalization()
            ->ifTrue(static fn (mixed $value): bool => is_array($value) && array_key_exists('async', $value))
            ->then(static function (): never {
                throw new InvalidConfigurationException('"somework_cqrs.async.dispatch_after_current_bus" moved to "somework_cqrs.dispatch_after_current_bus".');
            })
        ->end();
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
    /**
     * @param string $type "command", "query" or "event": a key of another message type never matches
     */
    private static function messageKeyedMap(ArrayNodeDefinition $map, string $type): void
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
            ->always(static function (array $entries) use ($type): array {
                foreach (array_keys($entries) as $class) {
                    if (!class_exists((string) $class) && !interface_exists((string) $class)) {
                        throw new \InvalidArgumentException(sprintf('"%s" is not an existing class or interface; keys must be message class or interface names.', $class));
                    }

                    // Classes and interfaces of another message type are never dispatched on these buses.
                    $marker = self::MARKERS[$type] ?? null;
                    foreach (self::MARKERS as $otherType => $other) {
                        if (null !== $marker && $otherType !== $type && is_a((string) $class, $other, true) && !is_a((string) $class, $marker, true)) {
                            throw new \InvalidArgumentException(sprintf('"%s" is a %s, not a %s: it never matches here.', $class, $otherType, $type));
                        }
                    }
                }

                return $entries;
            })
        ->end();
    }
}
