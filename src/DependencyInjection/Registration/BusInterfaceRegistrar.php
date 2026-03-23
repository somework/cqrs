<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Registration;

use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @internal */
final class BusInterfaceRegistrar
{
    public function register(ContainerBuilder $container): void
    {
        $container->setAlias(CommandBusInterface::class, CommandBus::class)->setPublic(true);
        $container->setAlias(QueryBusInterface::class, QueryBus::class)->setPublic(true);
        $container->setAlias(EventBusInterface::class, EventBus::class)->setPublic(true);
    }
}
