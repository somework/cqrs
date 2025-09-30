<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection;

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
            ->scalarNode('command')
            ->defaultValue(NullMessageSerializer::class)
            ->info('MessageSerializer service id applied to commands.');
        $serializationChildren
            ->scalarNode('event')
            ->defaultValue(NullMessageSerializer::class)
            ->info('MessageSerializer service id applied to events.');
        $serializationChildren
            ->scalarNode('query')
            ->defaultValue(NullMessageSerializer::class)
            ->info('MessageSerializer service id applied to queries.');
        $serializationChildren->end();
        $serialization->end();

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
}
