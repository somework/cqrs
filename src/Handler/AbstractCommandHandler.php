<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;

/**
 * Base class for command handlers that exposes a typed {@see handle()} method.
 *
 * @template TCommand of Command
 */
abstract class AbstractCommandHandler implements CommandHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    final public function __invoke(Command $command): mixed
    {
        return $this->handle($command);
    }

    /**
     * @param TCommand $command
     */
    abstract protected function handle(Command $command): mixed;
}
