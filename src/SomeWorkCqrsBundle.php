<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle;

use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CausationIdMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsRetryStrategyPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\EnvelopeAwareHandlersLocatorPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\OpenTelemetryMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateHandlerCountPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateIdempotencyDependenciesPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** @internal */
final class SomeWorkCqrsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Before Symfony's MessengerPass (priority 0): normalises handler tags and buses.
        $container->addCompilerPass(new CqrsHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        // After MessengerPass: decorates the handlers locators it registers.
        $container->addCompilerPass(new EnvelopeAwareHandlersLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -8);
        $container->addCompilerPass(new AllowNoHandlerMiddlewarePass(), PassConfig::TYPE_OPTIMIZE);
        $container->addCompilerPass(new CausationIdMiddlewarePass(), PassConfig::TYPE_OPTIMIZE);
        $container->addCompilerPass(new OpenTelemetryMiddlewarePass(), PassConfig::TYPE_OPTIMIZE);
        $container->addCompilerPass(new CqrsRetryStrategyPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new ValidateIdempotencyDependenciesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1);
        $container->addCompilerPass(new ValidateTransportNamesPass());
        $container->addCompilerPass(new ValidateHandlerCountPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new CqrsExtension();
        }

        return $this->extension;
    }
}
