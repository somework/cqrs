<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\HandlesAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\InterfaceOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\IntersectionTypeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\MethodAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\MixedUnionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\NonCqrsHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\NoParamHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskNotificationHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskProcessManager;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\UnionIntersectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\UnroutableIntersectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\UntypedInterfaceHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryableImportLegacyDataCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function array_column;
use function sprintf;

#[CoversClass(CqrsHandlerPass::class)]
final class CqrsHandlerPassTest extends TestCase
{
    public function test_it_collects_metadata_for_union_and_intersection_types(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.union_intersection_handler', UnionIntersectionHandler::class)
            ->addTag('messenger.message_handler');

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadataRaw = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadataRaw);
        /** @var array<string, list<array<string, mixed>>> $metadata */
        $metadata = $metadataRaw;
        self::assertArrayHasKey('command', $metadata);
        self::assertArrayHasKey('query', $metadata);
        self::assertArrayHasKey('event', $metadata);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);

        $commandMetadata = $metadata['command'];
        self::assertCount(2, $commandMetadata);

        $messages = array_column($commandMetadata, 'message');
        self::assertContains(CreateTaskCommand::class, $messages);
        self::assertContains(RetryableImportLegacyDataCommand::class, $messages);

        self::assertContains([
            'type' => 'command',
            'message' => CreateTaskCommand::class,
            'handler_class' => UnionIntersectionHandler::class,
            'service_id' => 'somework_cqrs.tests.union_intersection_handler',
            'bus' => null,
        ], $commandMetadata);

        self::assertContains([
            'type' => 'command',
            'message' => RetryableImportLegacyDataCommand::class,
            'handler_class' => UnionIntersectionHandler::class,
            'service_id' => 'somework_cqrs.tests.union_intersection_handler',
            'bus' => null,
        ], $commandMetadata);
    }

    public function test_it_uses_handles_attribute_strings_and_arrays(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.handles_array_handler', HandlesAttributeHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => [
                    CreateTaskCommand::class,
                    FindTaskQuery::class,
                    CreateTaskCommand::class,
                    OrderPlacedEvent::class,
                ],
                'bus' => 'cqrs.bus',
            ]);

        $container->register('somework_cqrs.tests.handles_string_handler', HandlesAttributeHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => OrderPlacedEvent::class,
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadataRaw = $container->getParameter('somework_cqrs.handler_metadata');
        self::assertIsArray($metadataRaw);
        /** @var array<string, list<array<string, mixed>>> $metadata */
        $metadata = $metadataRaw;

        $commandMetadata = $metadata['command'];
        self::assertSame([
            [
                'type' => 'command',
                'message' => CreateTaskCommand::class,
                'handler_class' => HandlesAttributeHandler::class,
                'service_id' => 'somework_cqrs.tests.handles_array_handler',
                'bus' => 'cqrs.bus',
            ],
        ], $commandMetadata);

        $queryMetadata = $metadata['query'];
        self::assertSame([
            [
                'type' => 'query',
                'message' => FindTaskQuery::class,
                'handler_class' => HandlesAttributeHandler::class,
                'service_id' => 'somework_cqrs.tests.handles_array_handler',
                'bus' => 'cqrs.bus',
            ],
        ], $queryMetadata);

        $eventMetadata = $metadata['event'];
        self::assertCount(2, $eventMetadata);

        self::assertContains([
            'type' => 'event',
            'message' => OrderPlacedEvent::class,
            'handler_class' => HandlesAttributeHandler::class,
            'service_id' => 'somework_cqrs.tests.handles_array_handler',
            'bus' => 'cqrs.bus',
        ], $eventMetadata);

        self::assertContains([
            'type' => 'event',
            'message' => OrderPlacedEvent::class,
            'handler_class' => HandlesAttributeHandler::class,
            'service_id' => 'somework_cqrs.tests.handles_string_handler',
            'bus' => null,
        ], $eventMetadata);
    }

    public function test_it_supports_associative_handles_definitions(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.associative_handles_handler', HandlesAttributeHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => [
                    CreateTaskCommand::class => 'handleCreate',
                    FindTaskQuery::class => ['from_transport' => 'async'],
                    OrderPlacedEvent::class => ['method' => 'onEvent'],
                ],
                'bus' => 'cqrs.bus',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadataRaw = $container->getParameter('somework_cqrs.handler_metadata');
        self::assertIsArray($metadataRaw);
        /** @var array<string, list<array<string, mixed>>> $metadata */
        $metadata = $metadataRaw;

        self::assertSame([
            [
                'type' => 'command',
                'message' => CreateTaskCommand::class,
                'handler_class' => HandlesAttributeHandler::class,
                'service_id' => 'somework_cqrs.tests.associative_handles_handler',
                'bus' => 'cqrs.bus',
            ],
        ], $metadata['command']);

        self::assertSame([
            [
                'type' => 'query',
                'message' => FindTaskQuery::class,
                'handler_class' => HandlesAttributeHandler::class,
                'service_id' => 'somework_cqrs.tests.associative_handles_handler',
                'bus' => 'cqrs.bus',
            ],
        ], $metadata['query']);

        self::assertSame([
            [
                'type' => 'event',
                'message' => OrderPlacedEvent::class,
                'handler_class' => HandlesAttributeHandler::class,
                'service_id' => 'somework_cqrs.tests.associative_handles_handler',
                'bus' => 'cqrs.bus',
            ],
        ], $metadata['event']);
    }

    public function test_it_resolves_child_definition_handler_class(): void
    {
        $container = new ContainerBuilder();

        $container->register('somework_cqrs.tests.parent_handler', CreateTaskHandler::class)
            ->setAbstract(true);

        $child = new ChildDefinition('somework_cqrs.tests.parent_handler');
        $child->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class]);
        $container->setDefinition('somework_cqrs.tests.child_handler', $child);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['command']);
        self::assertSame(CreateTaskHandler::class, $metadata['command'][0]['handler_class']);
    }

    public function test_it_skips_child_definition_with_unresolvable_parent(): void
    {
        $container = new ContainerBuilder();

        $container->register('somework_cqrs.tests.parent_no_class')
            ->setAbstract(true);

        $child = new ChildDefinition('somework_cqrs.tests.parent_no_class');
        $child->addTag('messenger.message_handler');
        $container->setDefinition('somework_cqrs.tests.child_no_class', $child);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_it_resolves_parameterized_class_name(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('handler.class', CreateTaskHandler::class);
        $container->register('somework_cqrs.tests.parameterized_handler', '%handler.class%')
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['command']);
        self::assertSame(CreateTaskHandler::class, $metadata['command'][0]['handler_class']);
    }

    public function test_it_skips_handler_with_no_invoke_parameters(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.no_param_handler', NoParamHandler::class)
            ->addTag('messenger.message_handler');

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_it_skips_non_cqrs_message_type(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.non_cqrs_handler', NonCqrsHandler::class)
            ->addTag('messenger.message_handler');

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_it_discovers_method_attribute_hint(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.method_attribute_handler', MethodAttributeHandler::class)
            ->addTag('messenger.message_handler', [
                'method' => 'handle',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadataRaw = $container->getParameter('somework_cqrs.handler_metadata');
        self::assertIsArray($metadataRaw);
        /** @var array<string, list<array<string, mixed>>> $metadata */
        $metadata = $metadataRaw;
        $commandMetadata = $metadata['command'];

        self::assertContains([
            'type' => 'command',
            'message' => CreateTaskCommand::class,
            'handler_class' => MethodAttributeHandler::class,
            'service_id' => 'somework_cqrs.tests.method_attribute_handler',
            'bus' => null,
        ], $commandMetadata);
    }

    public function test_it_discovers_pure_intersection_type_handler(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.intersection_type_handler', IntersectionTypeHandler::class)
            ->addTag('messenger.message_handler');

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadataRaw = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadataRaw);
        /** @var array<string, list<array<string, mixed>>> $metadata */
        $metadata = $metadataRaw;

        self::assertCount(1, $metadata['command']);
        self::assertSame(CreateTaskCommand::class, $metadata['command'][0]['message']);
        self::assertSame(IntersectionTypeHandler::class, $metadata['command'][0]['handler_class']);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_attribute_only_command_handler_discovered(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.attr_command_handler', AttributeOnlyCommandHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => PlainCommand::class,
                'bus' => 'messenger.bus.commands',
                'somework_cqrs_type' => 'command',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['command']);
        self::assertSame([
            'type' => 'command',
            'message' => PlainCommand::class,
            'handler_class' => AttributeOnlyCommandHandler::class,
            'service_id' => 'somework_cqrs.tests.attr_command_handler',
            'bus' => 'messenger.bus.commands',
        ], $metadata['command'][0]);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_attribute_only_query_handler_discovered(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.attr_query_handler', AttributeOnlyQueryHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => PlainQuery::class,
                'bus' => 'messenger.bus.queries',
                'somework_cqrs_type' => 'query',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['query']);
        self::assertSame([
            'type' => 'query',
            'message' => PlainQuery::class,
            'handler_class' => AttributeOnlyQueryHandler::class,
            'service_id' => 'somework_cqrs.tests.attr_query_handler',
            'bus' => 'messenger.bus.queries',
        ], $metadata['query'][0]);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['event']);
    }

    public function test_attribute_only_event_handler_discovered(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.attr_event_handler', AttributeOnlyEventHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => PlainEvent::class,
                'bus' => 'messenger.bus.events',
                'somework_cqrs_type' => 'event',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['event']);
        self::assertSame([
            'type' => 'event',
            'message' => PlainEvent::class,
            'handler_class' => AttributeOnlyEventHandler::class,
            'service_id' => 'somework_cqrs.tests.attr_event_handler',
            'bus' => 'messenger.bus.events',
        ], $metadata['event'][0]);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['query']);
    }

    public function test_both_attribute_and_interface_not_duplicated(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.dual_handler', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => CreateTaskCommand::class,
                'bus' => 'messenger.bus.commands',
                'somework_cqrs_type' => 'command',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        // CreateTaskCommand implements Command interface, so determineType finds it.
        // The somework_cqrs_type fallback is not used. Only one entry expected.
        self::assertCount(1, $metadata['command']);
        self::assertSame(CreateTaskCommand::class, $metadata['command'][0]['message']);
    }

    public function test_existing_interface_handlers_still_work(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.create_task_handler', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertCount(1, $metadata['command']);
        self::assertSame([
            'type' => 'command',
            'message' => CreateTaskCommand::class,
            'handler_class' => CreateTaskHandler::class,
            'service_id' => 'somework_cqrs.tests.create_task_handler',
            'bus' => null,
        ], $metadata['command'][0]);
    }

    public function test_attribute_without_cqrs_type_tag_still_skipped(): void
    {
        $container = new ContainerBuilder();
        // No somework_cqrs_type tag attribute - plain message without interface
        $container->register('somework_cqrs.tests.no_type_handler', AttributeOnlyCommandHandler::class)
            ->addTag('messenger.message_handler', [
                'handles' => PlainCommand::class,
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
        self::assertSame([], $metadata['command']);
        self::assertSame([], $metadata['query']);
        self::assertSame([], $metadata['event']);
    }

    public function test_handler_without_explicit_bus_is_registered_on_sync_and_async_bus(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.create_task', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class, 'somework_cqrs_type' => 'command']);

        (new CqrsHandlerPass())->process($container);

        self::assertSame(
            [
                // The internal type marker is not passed on to Messenger.
                ['handles' => CreateTaskCommand::class, 'bus' => 'messenger.bus.commands'],
                ['handles' => CreateTaskCommand::class, 'bus' => 'messenger.bus.commands_async'],
            ],
            $container->getDefinition('handler.create_task')->getTag('messenger.message_handler'),
        );
    }

    public function test_bus_aliases_are_resolved_to_real_bus_ids(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.default_bus');
        $container->register('messenger.bus.default');
        $container->setAlias('messenger.default_bus', 'messenger.bus.default');
        $container->setAlias('app.query_bus', 'messenger.default_bus');
        $container->register('handler.create_task', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class, 'somework_cqrs_type' => 'command']);
        $container->register('handler.find_task', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', ['handles' => FindTaskQuery::class, 'somework_cqrs_type' => 'query', 'bus' => 'app.query_bus']);

        (new CqrsHandlerPass())->process($container);

        self::assertSame('messenger.bus.default', $container->getDefinition('handler.create_task')->getTag('messenger.message_handler')[0]['bus']);
        self::assertSame('messenger.bus.default', $container->getDefinition('handler.find_task')->getTag('messenger.message_handler')[0]['bus']);
    }

    public function test_explicit_bus_is_kept_as_the_only_bus(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.create_task', CreateTaskHandler::class)
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class, 'somework_cqrs_type' => 'command', 'bus' => 'messenger.bus.commands_async']);

        (new CqrsHandlerPass())->process($container);

        $tags = $container->getDefinition('handler.create_task')->getTag('messenger.message_handler');
        self::assertCount(1, $tags);
        self::assertSame('messenger.bus.commands_async', $tags[0]['bus']);
    }

    public function test_foreign_handler_tags_keep_messenger_default_buses(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.method', MethodAttributeHandler::class)
            ->addTag('messenger.message_handler', ['method' => 'handle']);

        (new CqrsHandlerPass())->process($container);

        $tags = $container->getDefinition('handler.method')->getTag('messenger.message_handler');
        self::assertCount(1, $tags);
        self::assertArrayNotHasKey('bus', $tags[0]);
        self::assertSame(CreateTaskCommand::class, $tags[0]['handles']);
    }

    public function test_union_types_keep_routes_for_non_cqrs_members(): void
    {
        $container = new ContainerBuilder();
        $container->register('handler.mixed', MixedUnionHandler::class)
            ->addTag('messenger.message_handler');

        (new CqrsHandlerPass())->process($container);

        $handles = array_column($container->getDefinition('handler.mixed')->getTag('messenger.message_handler'), 'handles');
        self::assertSame([CreateTaskCommand::class, \stdClass::class], $handles);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');
        self::assertIsArray($metadata);
        self::assertSame([CreateTaskCommand::class], array_column($metadata['command'], 'message'));
    }

    public function test_unroutable_intersection_type_fails_with_a_clear_message(): void
    {
        $container = new ContainerBuilder();
        $container->register('handler.intersection', UnroutableIntersectionHandler::class)
            ->addTag('messenger.message_handler');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be routed by Symfony Messenger');

        (new CqrsHandlerPass())->process($container);
    }

    public function test_interface_handler_without_resolvable_message_fails_with_a_clear_message(): void
    {
        $container = new ContainerBuilder();
        $container->register('handler.untyped', UntypedInterfaceHandler::class)
            ->addTag('somework_cqrs.handler_interface', ['method' => '__invoke', 'type' => 'command']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot determine the message handled by');

        (new CqrsHandlerPass())->process($container);
    }

    public function test_interface_tag_is_ignored_when_an_explicit_handler_tag_exists(): void
    {
        $container = new ContainerBuilder();
        $container->register('handler.untyped', UntypedInterfaceHandler::class)
            ->addTag('somework_cqrs.handler_interface', ['method' => '__invoke', 'type' => 'command'])
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class, 'somework_cqrs_type' => 'command']);

        (new CqrsHandlerPass())->process($container);

        $definition = $container->getDefinition('handler.untyped');
        self::assertFalse($definition->hasTag('somework_cqrs.handler_interface'));
        self::assertCount(1, $definition->getTag('messenger.message_handler'));
    }

    private function createContainerWithBuses(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.default_bus', 'messenger.bus.commands');
        $container->setParameter('somework_cqrs.bus.command', 'messenger.bus.commands');
        $container->setParameter('somework_cqrs.bus.command_async', 'messenger.bus.commands_async');

        return $container;
    }

    public function test_an_option_less_tag_is_ignored_next_to_configured_ones(): void
    {
        $container = $this->createContainerWithBuses();
        // FrameworkBundle autoconfigures BatchHandlerInterface with an option-less tag.
        $container->register('handler.create_task', CreateTaskHandler::class)
            ->addTag('messenger.message_handler')
            ->addTag('messenger.message_handler', ['handles' => CreateTaskCommand::class, 'bus' => 'messenger.bus.commands_async']);

        (new CqrsHandlerPass())->process($container);

        self::assertSame(
            [['handles' => CreateTaskCommand::class, 'bus' => 'messenger.bus.commands_async']],
            $container->getDefinition('handler.create_task')->getTag('messenger.message_handler'),
        );
    }

    public function test_a_method_level_handler_does_not_hide_the_interface_registration(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.interface', InterfaceOnlyCommandHandler::class)
            ->addTag(CqrsHandlerPass::INTERFACE_TAG, ['method' => '__invoke', 'type' => 'command'])
            ->addTag('messenger.message_handler', ['method' => 'onSomethingElse', 'handles' => \stdClass::class]);

        (new CqrsHandlerPass())->process($container);

        $handled = array_map(static fn (array $tag): ?string => $tag['handles'] ?? null, $container->getDefinition('handler.interface')->getTag('messenger.message_handler'));
        self::assertContains(\stdClass::class, $handled);
        self::assertContains(GenerateReportCommand::class, $handled);
    }

    public function test_abstract_services_implementing_a_handler_interface_are_skipped(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.base', InterfaceOnlyCommandHandler::class)
            ->setAbstract(true)
            ->addTag(CqrsHandlerPass::INTERFACE_TAG, ['method' => '__invoke', 'type' => 'command']);

        (new CqrsHandlerPass())->process($container);

        self::assertFalse($container->getDefinition('handler.base')->hasTag('messenger.message_handler'));
        self::assertFalse($container->getDefinition('handler.base')->hasTag(CqrsHandlerPass::INTERFACE_TAG));
    }

    public function test_an_attribute_of_the_wrong_type_is_rejected(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.wrong', TaskNotificationHandler::class)
            ->addTag('messenger.message_handler', ['handles' => TaskCreatedEvent::class, CqrsHandlerPass::TYPE_ATTRIBUTE => 'command']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('is registered as a command handler, but %s is an event', TaskCreatedEvent::class));

        (new CqrsHandlerPass())->process($container);
    }

    public function test_a_union_handler_with_two_marker_interfaces_gets_each_member_on_its_buses(): void
    {
        $container = $this->createContainerWithBuses();
        $container->register('handler.process_manager', TaskProcessManager::class)
            ->addTag(CqrsHandlerPass::INTERFACE_TAG, ['method' => '__invoke', 'type' => 'command'])
            ->addTag(CqrsHandlerPass::INTERFACE_TAG, ['method' => '__invoke', 'type' => 'event']);

        (new CqrsHandlerPass())->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');
        self::assertIsArray($metadata);
        self::assertSame([CreateTaskCommand::class], array_values(array_unique(array_column($metadata['command'], 'message'))));
        self::assertSame([TaskCreatedEvent::class], array_values(array_unique(array_column($metadata['event'], 'message'))));
    }
}
