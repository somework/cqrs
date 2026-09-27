<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\DoctrineEventsPass;
use SomeWork\CqrsBundle\DependencyInjection\Registration\DoctrineEventsRegistrar;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

#[CoversClass(DoctrineEventsPass::class)]
final class DoctrineEventsPassTest extends TestCase
{
    public function test_does_nothing_without_the_feature(): void
    {
        $container = new ContainerBuilder();

        (new DoctrineEventsPass())->process($container);

        self::assertFalse($container->hasDefinition(DoctrineEventsRegistrar::LISTENER_ID));
    }

    public function test_accepts_doctrine_bundle_with_the_orm(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        (new DoctrineEventsPass())->process($container);

        self::assertTrue($container->hasDefinition(DoctrineEventsRegistrar::LISTENER_ID));
    }

    public function test_fails_without_doctrine_bundle(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"somework_cqrs.doctrine_events" needs DoctrineBundle with the ORM configured ("doctrine.orm")');

        (new DoctrineEventsPass())->process($this->container());
    }

    public function test_fails_without_the_orm_configuration(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs DoctrineBundle with the ORM configured');

        (new DoctrineEventsPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(DoctrineEventsRegistrar::LISTENER_ID, RecordedEventsListener::class);

        return $container;
    }
}
