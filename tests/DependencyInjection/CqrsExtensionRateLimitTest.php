<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\DependencyInjection\Configuration;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\RateLimitRegistrar;
use SomeWork\CqrsBundle\DependencyInjection\Registration\StampsDeciderRegistrar;
use SomeWork\CqrsBundle\Support\RateLimitResolver;
use SomeWork\CqrsBundle\Support\RateLimitStampDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function sprintf;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(RateLimitRegistrar::class)]
#[CoversClass(StampsDeciderRegistrar::class)]
final class CqrsExtensionRateLimitTest extends TestCase
{
    public function test_config_processes_with_empty_maps(): void
    {
        $container = $this->createContainer();

        foreach (['command', 'query', 'event'] as $type) {
            self::assertTrue(
                $container->hasDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)),
                sprintf('RateLimitResolver should be registered for %s type with empty map', $type),
            );

            $definition = $container->getDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type));
            self::assertSame(RateLimitResolver::class, $definition->getClass());
        }
    }

    public function test_config_processes_with_one_command_mapping(): void
    {
        $container = $this->createContainer([
            'rate_limiting' => [
                'command' => [
                    'map' => [
                        'App\\Message\\CreateOrder' => 'order_limiter',
                    ],
                ],
            ],
        ]);

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.rate_limit.command_resolver'),
            'RateLimitResolver should be registered for command type with one mapping',
        );

        $definition = $container->getDefinition('somework_cqrs.rate_limit.command_resolver');
        self::assertSame(RateLimitResolver::class, $definition->getClass());
    }

    public function test_config_processes_with_mappings_across_all_types(): void
    {
        $container = $this->createContainer([
            'rate_limiting' => [
                'command' => [
                    'map' => [
                        'App\\Message\\CreateOrder' => 'order_limiter',
                    ],
                ],
                'query' => [
                    'map' => [
                        'App\\Query\\SearchProducts' => 'search_limiter',
                    ],
                ],
                'event' => [
                    'map' => [
                        'App\\Event\\NotificationSent' => 'notification_limiter',
                    ],
                ],
            ],
        ]);

        foreach (['command', 'query', 'event'] as $type) {
            self::assertTrue(
                $container->hasDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)),
                sprintf('RateLimitResolver should be registered for %s type', $type),
            );
        }
    }

    public function test_rate_limiting_disabled_skips_registration(): void
    {
        $container = $this->createContainer([
            'rate_limiting' => [
                'enabled' => false,
            ],
        ]);

        foreach (['command', 'query', 'event'] as $type) {
            self::assertFalse(
                $container->hasDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)),
                sprintf('RateLimitResolver should NOT be registered for %s when rate_limiting.enabled=false', $type),
            );
        }
    }

    public function test_rate_limiting_enabled_parameter_set_to_true_by_default(): void
    {
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.rate_limiting.enabled'));
        self::assertTrue($container->getParameter('somework_cqrs.rate_limiting.enabled'));
    }

    public function test_rate_limiting_enabled_parameter_set_to_false_when_disabled(): void
    {
        $container = $this->createContainer([
            'rate_limiting' => [
                'enabled' => false,
            ],
        ]);

        self::assertTrue($container->hasParameter('somework_cqrs.rate_limiting.enabled'));
        self::assertFalse($container->getParameter('somework_cqrs.rate_limiting.enabled'));
    }

    public function test_resolver_definition_is_not_public(): void
    {
        $container = $this->createContainer();

        foreach (['command', 'query', 'event'] as $type) {
            $definition = $container->getDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type));
            self::assertFalse($definition->isPublic());
        }
    }

    public function test_stamp_deciders_registered_with_priority_225(): void
    {
        $container = $this->createContainer();

        $expected = [
            'command' => Command::class,
            'query' => Query::class,
            'event' => Event::class,
        ];

        foreach ($expected as $type => $contract) {
            $serviceId = sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type);

            self::assertTrue(
                $container->hasDefinition($serviceId),
                sprintf('Rate limit stamp decider should be registered for %s', $type),
            );

            $definition = $container->getDefinition($serviceId);
            self::assertSame(RateLimitStampDecider::class, $definition->getClass());

            $tags = $definition->getTag('somework_cqrs.dispatch_stamp_decider');
            self::assertCount(1, $tags);
            self::assertSame(225, $tags[0]['priority']);
            self::assertSame([$contract], $tags[0]['message_types']);
        }
    }

    public function test_stamp_deciders_not_registered_when_disabled(): void
    {
        $container = $this->createContainer([
            'rate_limiting' => [
                'enabled' => false,
            ],
        ]);

        foreach (['command', 'query', 'event'] as $type) {
            self::assertFalse(
                $container->hasDefinition(sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type)),
                sprintf('Rate limit stamp decider should NOT be registered for %s when disabled', $type),
            );
        }
    }

    public function test_stamp_decider_service_ids_follow_naming_pattern(): void
    {
        $container = $this->createContainer();

        foreach (['command', 'query', 'event'] as $type) {
            self::assertTrue(
                $container->hasDefinition(sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type)),
                sprintf('Service ID should follow pattern somework_cqrs.stamp_decider.%s_rate_limit', $type),
            );
        }
    }

    public function test_class_exists_guard_present_in_extension(): void
    {
        $reflector = new \ReflectionClass(CqrsExtension::class);
        $source = file_get_contents((string) $reflector->getFileName());
        self::assertIsString($source);

        self::assertStringContainsString(
            'class_exists(RateLimiterFactory::class)',
            $source,
            'CqrsExtension must guard RateLimitRegistrar with class_exists(RateLimiterFactory::class)',
        );
    }

    public function test_limiter_service_references_use_limiter_prefix(): void
    {
        $reflector = new \ReflectionClass(RateLimitRegistrar::class);
        $source = file_get_contents((string) $reflector->getFileName());
        self::assertIsString($source);

        self::assertStringContainsString(
            "sprintf('limiter.%s', \$limiterName)",
            $source,
            'RateLimitRegistrar must reference limiter services using the limiter.{name} prefix',
        );
    }

    public function test_resolver_receives_logger_argument(): void
    {
        $container = $this->createContainer();

        $definition = $container->getDefinition('somework_cqrs.rate_limit.command_resolver');
        $loggerArg = $definition->getArgument('$logger');

        self::assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $loggerArg);
    }

    public function test_class_exists_guard_present_in_stamps_decider_registrar(): void
    {
        $reflector = new \ReflectionClass(StampsDeciderRegistrar::class);
        $source = file_get_contents((string) $reflector->getFileName());
        self::assertIsString($source);

        self::assertStringContainsString(
            'class_exists(RateLimiterFactory::class)',
            $source,
            'StampsDeciderRegistrar must guard rate limit decider registration with class_exists(RateLimiterFactory::class)',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(array $config = []): ContainerBuilder
    {
        $extension = new CqrsExtension();
        $container = new ContainerBuilder();

        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);
        $container->register('messenger.default_bus.messenger.handlers_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->setPublic(true);

        $extension->load([] === $config ? [] : [$config], $container);

        return $container;
    }
}
