<?php

declare(strict_types=1);

use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services
        ->load('SomeWork\\CqrsBundle\\', '../src/*')
        ->exclude('../src/DependencyInjection');

    $services->set(CommandBus::class)->public();
    $services->set(EventBus::class)->public();
    $services->set(QueryBus::class)->public();
};
