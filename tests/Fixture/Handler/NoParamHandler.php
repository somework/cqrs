<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

final class NoParamHandler
{
    public function __invoke(): void
    {
    }
}
