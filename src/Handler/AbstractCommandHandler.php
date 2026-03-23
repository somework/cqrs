<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Handler;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;

/**
 * @api
 *
 * Base class for command handlers that exposes a typed {@see handle()} method.
 *
 * @template TCommand of Command
 *
 * @implements CommandHandler<TCommand>
 */
abstract class AbstractCommandHandler implements CommandHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    /** @param TCommand $command */
    final public function __invoke($command): mixed
    {
        return $this->handle($command);
    }

    /**
     * @param TCommand $command
     */
    abstract protected function handle(Command $command): mixed;
}
