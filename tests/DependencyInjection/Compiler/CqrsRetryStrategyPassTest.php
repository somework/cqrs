<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsRetryStrategyPass;
use SomeWork\CqrsBundle\Retry\CqrsRetryStrategy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(CqrsRetryStrategyPass::class)]
final class CqrsRetryStrategyPassTest extends TestCase
{
    public function test_does_nothing_when_retry_strategy_locator_missing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('somework_cqrs.retry_strategy.transports', ['async' => 'command']);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', 0.0);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', 0);

        (new CqrsRetryStrategyPass())->process($container);

        self::assertFalse($container->hasDefinition('somework_cqrs.retry_strategy.async'));
    }

    public function test_does_nothing_when_transports_parameter_missing(): void
    {
        $container = new ContainerBuilder();

        $locatorDefinition = new Definition();
        $locatorDefinition->setArgument(0, []);
        $container->setDefinition('messenger.retry_strategy_locator', $locatorDefinition);

        (new CqrsRetryStrategyPass())->process($container);

        /** @var array<string, Reference> $refs */
        $refs = $container->getDefinition('messenger.retry_strategy_locator')->getArgument(0);
        self::assertSame([], $refs);
    }

    public function test_does_nothing_when_transports_empty(): void
    {
        $container = new ContainerBuilder();

        $locatorDefinition = new Definition();
        $locatorDefinition->setArgument(0, []);
        $container->setDefinition('messenger.retry_strategy_locator', $locatorDefinition);
        $container->setParameter('somework_cqrs.retry_strategy.transports', []);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', 0.0);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', 0);

        (new CqrsRetryStrategyPass())->process($container);

        /** @var array<string, Reference> $refs */
        $refs = $container->getDefinition('messenger.retry_strategy_locator')->getArgument(0);
        self::assertSame([], $refs);
    }

    public function test_creates_strategy_for_single_transport(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        (new CqrsRetryStrategyPass())->process($container);

        self::assertTrue($container->hasDefinition('somework_cqrs.retry_strategy.async'));

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');
        self::assertSame(CqrsRetryStrategy::class, $definition->getClass());

        /** @var Reference $resolverRef */
        $resolverRef = $definition->getArgument('$resolver');
        self::assertSame('somework_cqrs.retry.command_resolver', (string) $resolverRef);
    }

    public function test_preserves_original_strategy_as_fallback(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        $originalRef = new Reference('messenger.retry.async_strategy');
        $locator = $container->getDefinition('messenger.retry_strategy_locator');
        $locator->replaceArgument(0, ['async' => $originalRef]);

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');

        /** @var Reference $fallbackRef */
        $fallbackRef = $definition->getArgument('$fallback');
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Reference::class, $fallbackRef);
        self::assertSame('messenger.retry.async_strategy', (string) $fallbackRef);
    }

    public function test_handles_transport_with_no_existing_strategy(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');
        self::assertNull($definition->getArgument('$fallback'));
    }

    public function test_creates_strategies_for_multiple_transports(): void
    {
        $container = $this->createContainerWithTransports([
            'async' => 'command',
            'events_async' => 'event',
        ]);

        $container->setDefinition('somework_cqrs.retry.event_resolver', new Definition());

        (new CqrsRetryStrategyPass())->process($container);

        self::assertTrue($container->hasDefinition('somework_cqrs.retry_strategy.async'));
        self::assertTrue($container->hasDefinition('somework_cqrs.retry_strategy.events_async'));

        $commandDef = $container->getDefinition('somework_cqrs.retry_strategy.async');
        $eventDef = $container->getDefinition('somework_cqrs.retry_strategy.events_async');

        /** @var Reference $commandResolverRef */
        $commandResolverRef = $commandDef->getArgument('$resolver');
        self::assertSame('somework_cqrs.retry.command_resolver', (string) $commandResolverRef);

        /** @var Reference $eventResolverRef */
        $eventResolverRef = $eventDef->getArgument('$resolver');
        self::assertSame('somework_cqrs.retry.event_resolver', (string) $eventResolverRef);
    }

    public function test_wires_correct_resolver_per_message_type(): void
    {
        $container = $this->createContainerWithTransports(['queries' => 'query']);
        $container->setDefinition('somework_cqrs.retry.query_resolver', new Definition());

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.queries');

        /** @var Reference $resolverRef */
        $resolverRef = $definition->getArgument('$resolver');
        self::assertSame('somework_cqrs.retry.query_resolver', (string) $resolverRef);
    }

    public function test_injects_logger_as_optional_reference(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');

        /** @var Reference $loggerRef */
        $loggerRef = $definition->getArgument('$logger');
        /* @phpstan-ignore staticMethod.alreadyNarrowedType */
        self::assertInstanceOf(Reference::class, $loggerRef);
        self::assertSame('logger', (string) $loggerRef);
    }

    public function test_updates_locator_argument_with_new_references(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        (new CqrsRetryStrategyPass())->process($container);

        /** @var array<string, Reference> $refs */
        $refs = $container->getDefinition('messenger.retry_strategy_locator')->getArgument(0);
        self::assertArrayHasKey('async', $refs);
        self::assertSame('somework_cqrs.retry_strategy.async', (string) $refs['async']);
    }

    public function test_passes_jitter_and_max_delay_to_strategy(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command'], jitter: 0.2, maxDelay: 30000);

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');
        self::assertSame(0.2, $definition->getArgument('$jitter'));
        self::assertSame(30000, $definition->getArgument('$maxDelay'));
    }

    public function test_throws_when_resolver_definition_missing(): void
    {
        $container = new ContainerBuilder();

        $locatorDefinition = new Definition();
        $locatorDefinition->setArgument(0, []);
        $container->setDefinition('messenger.retry_strategy_locator', $locatorDefinition);
        $container->setParameter('somework_cqrs.retry_strategy.transports', ['async' => 'command']);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', 0.0);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', 0);

        // Intentionally NOT registering somework_cqrs.retry.command_resolver

        $this->expectException(\LogicException::class);

        (new CqrsRetryStrategyPass())->process($container);
    }

    public function test_throws_logic_exception_with_descriptive_message(): void
    {
        $container = new ContainerBuilder();

        $locatorDefinition = new Definition();
        $locatorDefinition->setArgument(0, []);
        $container->setDefinition('messenger.retry_strategy_locator', $locatorDefinition);
        $container->setParameter('somework_cqrs.retry_strategy.transports', ['my_transport' => 'event']);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', 0.0);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', 0);

        try {
            (new CqrsRetryStrategyPass())->process($container);
            self::fail('Expected LogicException was not thrown');
        } catch (\LogicException $e) {
            self::assertStringContainsString('my_transport', $e->getMessage());
            self::assertStringContainsString('somework_cqrs.retry.event_resolver', $e->getMessage());
            self::assertStringContainsString('event', $e->getMessage());
        }
    }

    public function test_preserves_existing_strategies_for_unconfigured_transports(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command']);

        $otherRef = new Reference('messenger.retry.other_strategy');
        $locator = $container->getDefinition('messenger.retry_strategy_locator');
        $locator->replaceArgument(0, ['async' => new Reference('messenger.retry.async_strategy'), 'other' => $otherRef]);

        (new CqrsRetryStrategyPass())->process($container);

        /** @var array<string, Reference> $refs */
        $refs = $container->getDefinition('messenger.retry_strategy_locator')->getArgument(0);

        // 'async' replaced with CQRS strategy
        self::assertSame('somework_cqrs.retry_strategy.async', (string) $refs['async']);
        // 'other' preserved untouched
        self::assertSame('messenger.retry.other_strategy', (string) $refs['other']);
    }

    public function test_strategy_definition_uses_correct_class_for_all_types(): void
    {
        $container = $this->createContainerWithTransports([
            'commands_transport' => 'command',
            'queries_transport' => 'query',
            'events_transport' => 'event',
        ]);
        $container->setDefinition('somework_cqrs.retry.query_resolver', new Definition());
        $container->setDefinition('somework_cqrs.retry.event_resolver', new Definition());

        (new CqrsRetryStrategyPass())->process($container);

        foreach (['commands_transport', 'queries_transport', 'events_transport'] as $transport) {
            $definition = $container->getDefinition('somework_cqrs.retry_strategy.'.$transport);
            self::assertSame(CqrsRetryStrategy::class, $definition->getClass());
        }
    }

    public function test_handles_zero_jitter_and_zero_max_delay_defaults(): void
    {
        $container = $this->createContainerWithTransports(['async' => 'command'], jitter: 0.0, maxDelay: 0);

        (new CqrsRetryStrategyPass())->process($container);

        $definition = $container->getDefinition('somework_cqrs.retry_strategy.async');
        self::assertSame(0.0, $definition->getArgument('$jitter'));
        self::assertSame(0, $definition->getArgument('$maxDelay'));
    }

    /**
     * @param array<string, string> $transports
     */
    private function createContainerWithTransports(array $transports, float $jitter = 0.0, int $maxDelay = 0): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $locatorDefinition = new Definition();
        $locatorDefinition->setArgument(0, []);
        $container->setDefinition('messenger.retry_strategy_locator', $locatorDefinition);
        $container->setParameter('somework_cqrs.retry_strategy.transports', $transports);
        $container->setParameter('somework_cqrs.retry_strategy.jitter', $jitter);
        $container->setParameter('somework_cqrs.retry_strategy.max_delay', $maxDelay);

        // Register the command resolver by default (most tests use it)
        $container->setDefinition('somework_cqrs.retry.command_resolver', new Definition());

        return $container;
    }
}
