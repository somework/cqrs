<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TransportBoundHandlers;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\DependencyInjection\Compiler\AttributeAutoconfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(AsCommandHandler::class)]
#[CoversClass(AsEventHandler::class)]
final class CqrsExtensionHandlerAttributesTest extends TestCase
{
    public function test_the_attributes_become_messenger_handler_tags(): void
    {
        self::assertSame(
            [['handles' => CreateTaskCommand::class, 'somework_cqrs_type' => 'command']],
            $this->handlerTags(CreateTaskHandler::class),
        );
    }

    public function test_priority_and_transport_restrictions_reach_messenger(): void
    {
        // Messenger orders the handlers of a message by "priority" and runs a handler with
        // "from_transport" only for messages received from that transport.
        self::assertSame(
            [['handles' => TaskCreatedEvent::class, 'somework_cqrs_type' => 'event', 'priority' => 10, 'from_transport' => 'audit']],
            $this->handlerTags(TransportBoundHandlers::class),
        );
    }

    /**
     * @param class-string $class
     *
     * @return array<array<string, mixed>>
     */
    private function handlerTags(string $class): array
    {
        $container = new ContainerBuilder();
        (new CqrsExtension())->load([], $container);
        $container->register($class, $class)->setAutoconfigured(true);

        (new AttributeAutoconfigurationPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);

        return $container->getDefinition($class)->getTag('messenger.message_handler');
    }
}
