<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\SendNotificationCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

#[CoversClass(ValidateTransportNamesPass::class)]
final class ValidateTransportNamesPassTest extends TestCase
{
    public function test_it_throws_when_transport_is_missing(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['missing'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Messenger transport "missing" configured for SomeWork CQRS is not defined.');

        $container->compile();
    }

    public function test_it_allows_known_transports(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $container->register('messenger.transport.known', \stdClass::class);

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'default' => ['known'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $container->compile();

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_an_unknown_transport_of_an_asynchronous_attribute(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', ['command' => [['message' => SendNotificationCommand::class]]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('#[Asynchronous(transport: "notifications")] on "%s" names a Messenger transport that is not defined.', SendNotificationCommand::class));

        (new ValidateTransportNamesPass())->process($container);
    }

    public function test_it_accepts_a_known_transport_of_an_asynchronous_attribute(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.handler_metadata', ['command' => [['message' => SendNotificationCommand::class], ['message' => AsyncTaskCommand::class]]]);
        $container->register('messenger.transport.notifications', \stdClass::class);

        (new ValidateTransportNamesPass())->process($container);

        $this->expectNotToPerformAssertions();
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $bundle = new SomeWorkCqrsBundle();
        $bundle->build($container);

        return $container;
    }
}
