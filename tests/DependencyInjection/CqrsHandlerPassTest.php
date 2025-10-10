<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\HandlesAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\MethodAttributeHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\UnionIntersectionHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\OrderPlacedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryableImportLegacyDataCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CqrsHandlerPassTest extends TestCase
{
    public function test_it_collects_metadata_for_union_and_intersection_types(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.union_intersection_handler', UnionIntersectionHandler::class)
            ->addTag('messenger.message_handler');

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

        self::assertIsArray($metadata);
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

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');

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

    public function test_it_discovers_method_attribute_hint(): void
    {
        $container = new ContainerBuilder();
        $container->register('somework_cqrs.tests.method_attribute_handler', MethodAttributeHandler::class)
            ->addTag('messenger.message_handler', [
                'method' => 'handle',
            ]);

        $pass = new CqrsHandlerPass();
        $pass->process($container);

        $metadata = $container->getParameter('somework_cqrs.handler_metadata');
        $commandMetadata = $metadata['command'];

        self::assertContains([
            'type' => 'command',
            'message' => CreateTaskCommand::class,
            'handler_class' => MethodAttributeHandler::class,
            'service_id' => 'somework_cqrs.tests.method_attribute_handler',
            'bus' => null,
        ], $commandMetadata);
    }
}
