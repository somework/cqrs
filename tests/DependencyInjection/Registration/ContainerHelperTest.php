<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection\Registration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use SomeWork\CqrsBundle\Handler\AbstractCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ContainerHelper::class)]
final class ContainerHelperTest extends TestCase
{
    public function test_ensure_service_exists_registers_private_definition(): void
    {
        $container = new ContainerBuilder();
        $helper = new ContainerHelper();

        $serviceId = $helper->ensureServiceExists($container, TaskRecorder::class);

        self::assertTrue($container->has($serviceId));
        $definition = $container->getDefinition($serviceId);

        self::assertFalse($definition->isPublic());
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
    }

    public function test_ensure_service_exists_leaves_abstract_classes_and_unknown_ids_alone(): void
    {
        $container = new ContainerBuilder();
        $helper = new ContainerHelper();

        self::assertSame(AbstractCommandHandler::class, $helper->ensureServiceExists($container, AbstractCommandHandler::class));
        self::assertSame('app.not_a_class', $helper->ensureServiceExists($container, 'app.not_a_class'));

        self::assertFalse($container->hasDefinition(AbstractCommandHandler::class));
        self::assertFalse($container->hasDefinition('app.not_a_class'));
    }

    public function test_ensure_service_exists_keeps_an_existing_definition(): void
    {
        $container = new ContainerBuilder();
        $existing = $container->register(TaskRecorder::class, TaskRecorder::class)->setPublic(true);

        (new ContainerHelper())->ensureServiceExists($container, TaskRecorder::class);

        self::assertSame($existing, $container->getDefinition(TaskRecorder::class));
    }
}
