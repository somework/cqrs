<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\DependencyInjection\Registration\ContainerHelper;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
}
