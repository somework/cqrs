<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle;

use SomeWork\CqrsBundle\DependencyInjection\CqrsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SomeWorkCqrsBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new CqrsExtension();
        }

        return $this->extension;
    }
}
