<?php

declare(strict_types=1);

use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Command\DebugTransportsCommand;
use SomeWork\CqrsBundle\Command\GenerateMessageCommand;
use SomeWork\CqrsBundle\Command\HealthCheckCommand;
use SomeWork\CqrsBundle\Command\ListHandlersCommand;
use SomeWork\CqrsBundle\Health\HandlerResolvabilityChecker;
use SomeWork\CqrsBundle\Health\TransportValidityChecker;
use SomeWork\CqrsBundle\Registry\HandlerRegistry;
use SomeWork\CqrsBundle\Support\ClassNameMessageNamingStrategy;
use SomeWork\CqrsBundle\Support\ExponentialBackoffRetryPolicy;
use SomeWork\CqrsBundle\Support\NullMessageSerializer;
use SomeWork\CqrsBundle\Support\NullRetryPolicy;
use SomeWork\CqrsBundle\Support\RandomCorrelationMetadataProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;

/*
 * Services with a fixed definition. Everything that depends on the bundle configuration
 * (resolvers, stamp deciders, middleware, outbox, rate limiting) is registered by the
 * registrars and compiler passes, so it is never registered twice.
 */
return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->set(CommandBus::class)->public();
    $services->set(EventBus::class)->public();
    $services->set(QueryBus::class)->public();

    $services->set(HandlerRegistry::class);

    $services->set(ListHandlersCommand::class);
    $services->set(GenerateMessageCommand::class);
    $services->set(DebugTransportsCommand::class);
    $services->set(HealthCheckCommand::class);

    // The service locators are set by HealthCheckerLocatorPass.
    $services->set(HandlerResolvabilityChecker::class)
        ->arg('$handlers', abstract_arg('handler services'));
    $services->set(TransportValidityChecker::class)
        ->arg('$transports', abstract_arg('transport services'));

    // Default policies referenced by class name from the configuration defaults.
    $services->set(ClassNameMessageNamingStrategy::class);
    $services->set(NullRetryPolicy::class);
    $services->set(NullMessageSerializer::class);
    $services->set(RandomCorrelationMetadataProvider::class);
    $services->set(ExponentialBackoffRetryPolicy::class);
};
