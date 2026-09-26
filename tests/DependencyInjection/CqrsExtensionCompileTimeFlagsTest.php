<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
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
