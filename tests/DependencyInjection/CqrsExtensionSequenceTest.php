<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use SomeWork\CqrsBundle\DependencyInjection\Registration\StampsDeciderRegistrar;
use SomeWork\CqrsBundle\Support\SequenceStampDecider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(CqrsExtension::class)]
#[CoversClass(StampsDeciderRegistrar::class)]
final class CqrsExtensionSequenceTest extends TestCase
{
    public function test_sequence_decider_registered_by_default(): void
    {
        $container = $this->createContainer();

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.stamp_decider.event_sequence'),
            'SequenceStampDecider should be registered when enabled=true (default)',
        );

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.event_sequence');
        self::assertSame(SequenceStampDecider::class, $definition->getClass());
    }

    public function test_sequence_decider_not_registered_when_disabled(): void
    {
        $container = $this->createContainer([
            'sequence' => ['enabled' => false],
        ]);

        self::assertFalse(
            $container->hasDefinition('somework_cqrs.stamp_decider.event_sequence'),
            'SequenceStampDecider should NOT be registered when sequence.enabled=false',
        );
    }

    public function test_sequence_decider_has_priority_110_and_event_message_types(): void
    {
        $container = $this->createContainer();

        $tags = $container->getDefinition('somework_cqrs.stamp_decider.event_sequence')
            ->getTag('somework_cqrs.dispatch_stamp_decider');

        self::assertCount(1, $tags);
        self::assertSame(110, $tags[0]['priority']);
        self::assertSame([Event::class], $tags[0]['message_types']);
    }

    public function test_sequence_enabled_parameter_set_to_true_by_default(): void
    {
        $container = $this->createContainer();

        self::assertTrue($container->hasParameter('somework_cqrs.sequence.enabled'));
        self::assertTrue($container->getParameter('somework_cqrs.sequence.enabled'));
    }

    public function test_sequence_enabled_parameter_set_to_false_when_disabled(): void
    {
        $container = $this->createContainer([
            'sequence' => ['enabled' => false],
        ]);

        self::assertTrue($container->hasParameter('somework_cqrs.sequence.enabled'));
        self::assertFalse($container->getParameter('somework_cqrs.sequence.enabled'));
    }

    public function test_sequence_decider_registered_when_explicitly_enabled(): void
    {
        $container = $this->createContainer([
            'sequence' => ['enabled' => true],
        ]);

        self::assertTrue(
            $container->hasDefinition('somework_cqrs.stamp_decider.event_sequence'),
        );

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.event_sequence');
        self::assertSame(SequenceStampDecider::class, $definition->getClass());
    }

    public function test_sequence_decider_service_is_not_public(): void
    {
        $container = $this->createContainer();

        $definition = $container->getDefinition('somework_cqrs.stamp_decider.event_sequence');
        self::assertFalse($definition->isPublic());
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
