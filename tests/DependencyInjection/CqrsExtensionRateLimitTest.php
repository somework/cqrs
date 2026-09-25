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
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;

use function sprintf;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(RateLimitRegistrar::class)]
#[CoversClass(StampsDeciderRegistrar::class)]
final class CqrsExtensionRateLimitTest extends TestCase
{
    private const ALL_TYPES_CONFIG = [
        'rate_limiting' => [
            'command' => ['map' => [CreateTaskCommand::class => 'order_limiter']],
            'query' => ['map' => [FindTaskQuery::class => 'search_limiter']],
            'event' => ['map' => [TaskCreatedEvent::class => 'notification_limiter']],
        ],
    ];

    public function test_default_config_registers_no_rate_limiting_services(): void
    {
        $container = $this->createContainer();

        foreach (['command', 'query', 'event'] as $type) {
            self::assertFalse($container->hasDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)));
            self::assertFalse($container->hasDefinition(sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type)));
        }
    }

    public function test_default_config_does_not_require_the_rate_limiter_component(): void
    {
        $container = $this->createContainer([], static fn (string $class): bool => RateLimiterFactory::class !== $class && class_exists($class));

        self::assertFalse($container->hasDefinition('somework_cqrs.stamp_decider.command_rate_limit'));
    }

    public function test_mappings_without_the_rate_limiter_component_fail_with_a_clear_message(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('symfony/rate-limiter is not installed');

        $this->createContainer(self::ALL_TYPES_CONFIG, static fn (string $class): bool => RateLimiterFactory::class !== $class && class_exists($class));
    }

    public function test_mappings_register_resolvers_for_every_type(): void
    {
        $container = $this->createContainer(self::ALL_TYPES_CONFIG);

        foreach (['command', 'query', 'event'] as $type) {
            $definition = $container->getDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type));

            self::assertSame(RateLimitResolver::class, $definition->getClass());
            self::assertFalse($definition->isPublic());
            self::assertInstanceOf(Reference::class, $definition->getArgument('$logger'));
        }
    }

    public function test_limiters_are_referenced_by_their_framework_service_id(): void
    {
        $container = $this->createContainer(self::ALL_TYPES_CONFIG);

        $locatorReference = $container->getDefinition('somework_cqrs.rate_limit.command_resolver')->getArgument('$limiters');
        self::assertInstanceOf(Reference::class, $locatorReference);

        $services = $container->getDefinition((string) $locatorReference)->getArgument(0);
        self::assertIsArray($services);
        self::assertArrayHasKey(CreateTaskCommand::class, $services);

        // Exactly one closure level: the locator must return the limiter itself, not a closure.
        $closure = $services[CreateTaskCommand::class];
        self::assertInstanceOf(ServiceClosureArgument::class, $closure);
        $values = $closure->getValues();
        self::assertCount(1, $values);
        self::assertInstanceOf(Reference::class, $values[0]);
        self::assertSame('limiter.order_limiter', (string) $values[0]);
    }

    public function test_a_default_limiter_activates_rate_limiting_for_every_type(): void
    {
        // Each message class consumes its own bucket of the default limiter.
        $container = $this->createContainer(['rate_limiting' => ['default' => 'api', 'event' => ['default' => 'events']]]);

        foreach (['command' => 'limiter.api', 'query' => 'limiter.api', 'event' => 'limiter.events'] as $type => $limiter) {
            $locator = $container->getDefinition($container->getDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type))->getArgument('$limiters')->__toString());
            $factories = $locator->getArgument(0);
            self::assertIsArray($factories);
            self::assertArrayHasKey(RateLimitResolver::DEFAULT_KEY, $factories, $type);
            self::assertSame($limiter, (string) $factories[RateLimitResolver::DEFAULT_KEY]->getValues()[0], $type);
        }
    }

    public function test_stamp_deciders_registered_with_priority_225(): void
    {
        $container = $this->createContainer(self::ALL_TYPES_CONFIG);

        $expected = [
            'command' => Command::class,
            'query' => Query::class,
            'event' => Event::class,
        ];

        foreach ($expected as $type => $contract) {
            $definition = $container->getDefinition(sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type));

            self::assertSame(RateLimitStampDecider::class, $definition->getClass());
            self::assertSame($contract, $definition->getArgument('$messageType'));

            $tags = $definition->getTag('somework_cqrs.dispatch_stamp_decider');
            self::assertCount(1, $tags);
            self::assertSame(225, $tags[0]['priority']);
        }
    }

    public function test_rate_limiting_disabled_skips_registration_even_with_mappings(): void
    {
        $config = self::ALL_TYPES_CONFIG;
        $config['rate_limiting']['enabled'] = false;

        $container = $this->createContainer($config);

        foreach (['command', 'query', 'event'] as $type) {
            self::assertFalse($container->hasDefinition(sprintf('somework_cqrs.rate_limit.%s_resolver', $type)));
            self::assertFalse($container->hasDefinition(sprintf('somework_cqrs.stamp_decider.%s_rate_limit', $type)));
        }
    }

    public function test_rate_limiting_enabled_parameter_reflects_the_configuration(): void
    {
        self::assertTrue($this->createContainer()->getParameter('somework_cqrs.rate_limiting.enabled'));
        self::assertFalse($this->createContainer(['rate_limiting' => ['enabled' => false]])->getParameter('somework_cqrs.rate_limiting.enabled'));
    }

    /**
     * @param array<string, mixed>          $config
     * @param (\Closure(string): bool)|null $classExists
     */
    private function createContainer(array $config = [], ?\Closure $classExists = null): ContainerBuilder
    {
        $container = new ContainerBuilder();

        (new CqrsExtension($classExists))->load([] === $config ? [] : [$config], $container);

        return $container;
    }
}
