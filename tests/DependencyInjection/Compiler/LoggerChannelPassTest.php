<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\LoggerChannelPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(LoggerChannelPass::class)]
#[CoversClass(CqrsExtension::class)]
final class LoggerChannelPassTest extends TestCase
{
    public function test_services_of_the_bundle_log_on_the_cqrs_channel(): void
    {
        $container = new ContainerBuilder();
        $container->register('monolog.logger.cqrs', NullLogger::class);
        $container->register('bus', CommandBus::class)
            ->setArguments(['$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE), '$nested' => [new Reference('logger')]])
            ->addMethodCall('setLogger', [new Reference('logger')]);
        $container->register('app', \stdClass::class)->setArguments([new Reference('logger')]);

        (new LoggerChannelPass())->process($container);

        $bus = $container->getDefinition('bus');
        self::assertSame(new Reference('monolog.logger.cqrs', ContainerInterface::NULL_ON_INVALID_REFERENCE), $bus->getArgument('$logger'));
        self::assertSame([new Reference('monolog.logger.cqrs')], $bus->getArgument('$nested'));
        self::assertSame([['setLogger', [new Reference('monolog.logger.cqrs')]]], $bus->getMethodCalls());
        self::assertSame(new Reference('logger'), $container->getDefinition('app')->getArgument(0), 'Services of the application are left alone.');
    }

    public function test_without_the_channel_the_logger_is_kept(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('bus', (new Definition(CommandBus::class))->setArguments([new Reference('logger')]));

        (new LoggerChannelPass())->process($container);

        self::assertSame(new Reference('logger'), $container->getDefinition('bus')->getArgument(0));
    }

    public function test_the_extension_declares_the_channel_when_monolog_is_installed(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'monolog';
            }
        });

        (new CqrsExtension())->prepend($container);

        self::assertSame([['channels' => ['cqrs']]], $container->getExtensionConfig('monolog'));
    }

    public function test_the_extension_declares_nothing_without_monolog(): void
    {
        $container = new ContainerBuilder();

        (new CqrsExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('monolog'));
    }
}
