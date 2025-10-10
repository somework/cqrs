<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

final class CqrsExtensionSendMessageStampTest extends TestCase
{
    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_it_requires_send_message_stamp_class(): void
    {
        $extension = new CqrsExtension();
        $container = $this->createContainer();

        try {
            $extension->load([
                [
                    'transports' => [
                        'command' => [
                            'stamp' => MessageTransportStampFactory::TYPE_SEND_MESSAGE,
                            'default' => ['async'],
                            'map' => [],
                        ],
                    ],
                ],
            ], $container);

            $container->compile();
            self::fail('An InvalidConfigurationException was not thrown.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame(
                sprintf(
                    'The "send_message" transport stamp type requires the "%s" class. Upgrade symfony/messenger to a version that provides it.',
                    MessageTransportStampFactory::SEND_MESSAGE_TO_TRANSPORTS_STAMP_CLASS,
                ),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_it_allows_send_message_stamp_when_class_is_available(): void
    {
        require_once __DIR__.'/../Fixture/Messenger/SendMessageToTransportsStampStub.php';

        $extension = new CqrsExtension();
        $container = $this->createContainer();

        $extension->load([
            [
                'transports' => [
                    'command' => [
                        'stamp' => MessageTransportStampFactory::TYPE_SEND_MESSAGE,
                        'default' => ['command-default'],
                        'map' => [],
                    ],
                ],
            ],
        ], $container);

        $container->compile();

        $stampTypes = $container->getParameter('somework_cqrs.transport_stamp_types');

        self::assertIsArray($stampTypes);
        self::assertArrayHasKey('command', $stampTypes);
        self::assertSame(MessageTransportStampFactory::TYPE_SEND_MESSAGE, $stampTypes['command']);
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        return $container;
    }
}
