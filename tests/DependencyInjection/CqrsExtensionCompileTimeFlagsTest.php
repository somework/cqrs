<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

/**
 * Runs the extension through the MergeExtensionConfigurationPass, like a kernel does, so
 * "%env(...)%" values reach the configuration as environment placeholders.
 */
#[CoversClass(CqrsExtension::class)]
final class CqrsExtensionCompileTimeFlagsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sections(): iterable
    {
        foreach (['outbox', 'idempotency', 'causation_id', 'sequence', 'rate_limiting'] as $section) {
            yield $section => [$section];
        }
    }

    #[DataProvider('sections')]
    public function test_rejects_an_environment_variable_for_enabled(string $section): void
    {
        $container = $this->container([$section => ['enabled' => '%env(bool:CQRS_FLAG)%']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"somework_cqrs.%s.enabled" decides which services are registered', $section));

        (new MergeExtensionConfigurationPass())->process($container);
    }

    public function test_accepts_booleans(): void
    {
        $container = $this->container(['sequence' => ['enabled' => false], 'idempotency' => ['enabled' => true]]);

        (new MergeExtensionConfigurationPass())->process($container);

        self::assertFalse($container->getParameter('somework_cqrs.sequence.enabled'));
    }

    public function test_idempotency_ttl_accepts_an_environment_variable(): void
    {
        $container = $this->container(['idempotency' => ['ttl' => '%env(int:CQRS_IDEMPOTENCY_TTL)%']]);

        (new MergeExtensionConfigurationPass())->process($container);

        $ttl = $container->getParameter('somework_cqrs.idempotency.ttl');
        self::assertIsString($ttl);
        self::assertSame('%env(int:CQRS_IDEMPOTENCY_TTL)%', $container->resolveEnvPlaceholders($ttl, '%%env(%s)%%'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function compileTimeOptions(): iterable
    {
        yield 'dispatch mode' => [['dispatch_modes' => ['command' => ['default' => '%env(MODE)%']]], 'dispatch_modes.command.default'];
        yield 'transport name' => [['transports' => ['command' => ['default' => ['%env(TRANSPORT)%']]]], 'transports.command.default'];
        yield 'transport map entry' => [['transports' => ['command' => ['map' => [CreateTaskCommand::class => '%env(TRANSPORT)%']]]], 'transports.command.map.'.CreateTaskCommand::class];
        yield 'retry strategy transport' => [['retry_strategy' => ['transports' => ['%env(TRANSPORT)%' => 'command']]], 'retry_strategy.transports'];
        yield 'bus id' => [['buses' => ['command' => '%env(BUS)%']], 'buses.command'];
        yield 'default bus' => [['default_bus' => '%env(BUS)%'], 'default_bus'];
        yield 'service id' => [['naming' => ['default' => '%env(SERVICE)%']], 'naming.default'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('compileTimeOptions')]
    public function test_rejects_an_environment_variable_in_a_compile_time_option(array $config, string $path): void
    {
        $container = $this->container($config);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('"somework_cqrs.%s" is used when the container is compiled', $path));

        (new MergeExtensionConfigurationPass())->process($container);
    }

    public function test_runtime_options_accept_environment_variables(): void
    {
        $container = $this->container([
            'retry_strategy' => ['jitter' => '%env(float:JITTER)%', 'max_delay' => '%env(int:MAX_DELAY)%'],
            'async' => ['dispatch_after_current_bus' => ['command' => ['default' => '%env(bool:DEFER)%']]],
        ]);

        (new MergeExtensionConfigurationPass())->process($container);

        self::assertIsString($container->getParameter('somework_cqrs.retry_strategy.jitter'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new CqrsExtension());
        $container->loadFromExtension('somework_cqrs', $config);

        return $container;
    }
}
