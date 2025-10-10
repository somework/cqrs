<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle;

use SomeWork\CqrsBundle\DependencyInjection\Compiler\AllowNoHandlerMiddlewarePass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\CqrsHandlerPass;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\ValidateTransportNamesPass;
use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SomeWorkCqrsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new CqrsHandlerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        $container->addCompilerPass(new AllowNoHandlerMiddlewarePass(), PassConfig::TYPE_OPTIMIZE);
        $container->addCompilerPass(new ValidateTransportNamesPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new CqrsExtension();
        }

        return $this->extension;
    }
}
