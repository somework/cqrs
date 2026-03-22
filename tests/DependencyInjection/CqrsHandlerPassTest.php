<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\HandlesAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\IntersectionTypeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\MethodAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\NonCqrsHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\NoParamHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\UnionIntersectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryableImportLegacyDataCommand;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_column;

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
}
