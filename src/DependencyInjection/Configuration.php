<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function sprintf;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('somework_cqrs');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $children = $rootNode->children();

        /** @var ScalarNodeDefinition $defaultBus */
        $defaultBus = $children->scalarNode('default_bus');
        $defaultBus
            ->defaultNull()
            ->info('Default message bus service id to use when dispatching messages.');
        $defaultBus->end();

        $buses = $children->arrayNode('buses');
        $buses
            ->addDefaultsIfNotSet()
            ->info('Per-message-type bus service ids used by the CQRS facades.');

        /** @var ArrayNodeDefinition $busesChildren */
        $busesChildren = $buses->children();

        /** @var ScalarNodeDefinition $commandBus */
        $commandBus = $busesChildren->scalarNode('command');
        $commandBus
            ->defaultNull()
            ->info('Synchronous command bus service id. Defaults to the Messenger default bus.');
        $commandBus->end();

        /** @var ScalarNodeDefinition $commandAsyncBus */
        $commandAsyncBus = $busesChildren->scalarNode('command_async');
        $commandAsyncBus
            ->defaultNull()
            ->info('Asynchronous command bus service id. Leave null to disable async dispatch.');
        $commandAsyncBus->end();

        /** @var ScalarNodeDefinition $queryBus */
        $queryBus = $busesChildren->scalarNode('query');
        $queryBus
            ->defaultNull()
            ->info('Query bus service id. Defaults to the Messenger default bus.');
        $queryBus->end();

        /** @var ScalarNodeDefinition $eventBus */
        $eventBus = $busesChildren->scalarNode('event');
        $eventBus
            ->defaultNull()
            ->info('Synchronous event bus service id. Defaults to the Messenger default bus.');
        $eventBus->end();

        /** @var ScalarNodeDefinition $eventAsyncBus */
        $eventAsyncBus = $busesChildren->scalarNode('event_async');
        $eventAsyncBus
            ->defaultNull()
            ->info('Asynchronous event bus service id. Leave null to disable async dispatch.');
        $eventAsyncBus->end();

        $busesChildren->end();
        $buses->end();

        $naming = $children->arrayNode('naming');
        $naming
            ->addDefaultsIfNotSet()
            ->info('Message naming strategies used by diagnostics and tooling.');

        /** @var ArrayNodeDefinition $namingChildren */
        $namingChildren = $naming->children();
        $namingChildren
            ->scalarNode('default')
            ->defaultValue(ClassNameMessageNamingStrategy::class)
            ->info('Service id implementing MessageNamingStrategy for all message types.');
        $namingChildren
            ->scalarNode('command')
            ->defaultNull()
            ->info('Overrides the default naming strategy for commands.');
        $namingChildren
            ->scalarNode('query')
            ->defaultNull()
            ->info('Overrides the default naming strategy for queries.');
        $namingChildren
            ->scalarNode('event')
            ->defaultNull()
            ->info('Overrides the default naming strategy for events.');
        $namingChildren->end();
        $naming->end();

        $retry = $children->arrayNode('retry_policies');
        $retry
            ->addDefaultsIfNotSet()
            ->info('Retry policy services applied when dispatching messages. Supports per-message overrides.');

        /** @var ArrayNodeDefinition $retryChildren */
        $retryChildren = $retry->children();
        $this->configureRetryPolicySection($retryChildren, 'command');
        $this->configureRetryPolicySection($retryChildren, 'event');
        $this->configureRetryPolicySection($retryChildren, 'query');
        $retryChildren->end();
        $retry->end();

        $serialization = $children->arrayNode('serialization');
        $serialization
            ->addDefaultsIfNotSet()
            ->info('MessageSerializer services that provide SerializerStamp instances.');

        /** @var ArrayNodeDefinition $serializationChildren */
        $serializationChildren = $serialization->children();
        $serializationChildren
            ->scalarNode('default')
            ->defaultValue(NullMessageSerializer::class)
            ->info('Fallback MessageSerializer service id applied to all messages.');

        $this->configureSerializerSection($serializationChildren, 'command');
        $this->configureSerializerSection($serializationChildren, 'event');
        $this->configureSerializerSection($serializationChildren, 'query');
        $serializationChildren->end();
        $serialization->end();

        $dispatchModes = $children->arrayNode('dispatch_modes');
        $dispatchModes
            ->addDefaultsIfNotSet()
            ->info('Default dispatch modes applied when no explicit mode is provided.');

        /** @var ArrayNodeDefinition $dispatchChildren */
        $dispatchChildren = $dispatchModes->children();
        $this->configureDispatchModeSection($dispatchChildren, 'command');
        $this->configureDispatchModeSection($dispatchChildren, 'event');
        $dispatchChildren->end();
        $dispatchModes->end();

        return $treeBuilder;
    }

    private function configureRetryPolicySection(NodeBuilder $parent, string $type): void
    {
        $node = $parent->arrayNode($type);
        $node
            ->addDefaultsIfNotSet()
            ->info(sprintf('RetryPolicy services applied to %s messages.', $type));

        $children = $node->children();
        $children
            ->scalarNode('default')
            ->defaultValue(NullRetryPolicy::class)
            ->info(sprintf('Fallback RetryPolicy service id applied to %s messages.', $type));

        $children
            ->arrayNode('map')
            ->useAttributeAsKey('message')
            ->scalarPrototype()
            ->end()
            ->info('Message-specific RetryPolicy service ids. Keys must match the message FQCN.');

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
        $children
            ->scalarNode('default')
            ->defaultNull()
            ->info(sprintf('Fallback MessageSerializer service id applied to %s messages. Falls back to serialization.default when null.', $type));

        $children
            ->arrayNode('map')
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->scalarPrototype()
            ->end()
            ->info('Message-specific MessageSerializer service ids. Keys must match the message FQCN.');

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

        $children
            ->arrayNode('map')
            ->useAttributeAsKey('message')
            ->defaultValue([])
            ->scalarPrototype()
                ->validate()
                    ->ifNotInArray([DispatchMode::SYNC->value, DispatchMode::ASYNC->value])
                    ->thenInvalid('Invalid dispatch mode "%s". Expected "sync" or "async".')
                ->end()
            ->end()
            ->info(sprintf('Message-specific dispatch mode overrides for %s messages.', $type));

        $children->end();
        $node->end();
    }
}
