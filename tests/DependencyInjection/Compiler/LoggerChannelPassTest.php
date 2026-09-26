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

use function array_map;
use function is_array;

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
        self::assertSame('@monolog.logger.cqrs (null on invalid)', self::describe($bus->getArgument('$logger')));
        self::assertSame(['@monolog.logger.cqrs'], self::describe($bus->getArgument('$nested')));
        self::assertSame([['setLogger', ['@monolog.logger.cqrs']]], self::describe($bus->getMethodCalls()));
        self::assertSame('@logger', self::describe($container->getDefinition('app')->getArgument(0)), 'Services of the application are left alone.');
    }

    public function test_without_the_channel_the_logger_is_kept(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('bus', (new Definition(CommandBus::class))->setArguments([new Reference('logger')]));

        (new LoggerChannelPass())->process($container);

        self::assertSame('@logger', self::describe($container->getDefinition('bus')->getArgument(0)));
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

    private static function describe(mixed $value): mixed
    {
        if ($value instanceof Reference) {
            return '@'.$value.(ContainerInterface::NULL_ON_INVALID_REFERENCE === $value->getInvalidBehavior() ? ' (null on invalid)' : '');
        }

        return is_array($value) ? array_map(self::describe(...), $value) : $value;
    }
}
