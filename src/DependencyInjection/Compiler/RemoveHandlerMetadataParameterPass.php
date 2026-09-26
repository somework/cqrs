<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the handler metadata parameter once HandlerRegistry received it as an argument: dumped
 * parameters live in the main container class, which every request loads (hundreds of kilobytes
 * with a thousand handlers), while the registry's factory is only loaded when it is used.
 *
 * @internal
 */
final class RemoveHandlerMetadataParameterPass implements CompilerPassInterface
{
    public const PARAMETER = 'somework_cqrs.handler_metadata';

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter(self::PARAMETER)) {
            $container->getParameterBag()->remove(self::PARAMETER);
        }
    }
}
