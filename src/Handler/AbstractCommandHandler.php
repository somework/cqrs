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
 * Base class for command handlers that also receive the Messenger envelope ({@see EnvelopeAware}).
 *
 * PHP does not let handle() narrow its parameter: it stays Command, and TCommand only
 * type it for static analysis. As __invoke() is untyped, declare the handled message with
 * #[AsCommandHandler(YourMessage::class)]. For new handlers, prefer implementing CommandHandler with a
 * typed __invoke(YourMessage $message) (and EnvelopeAware when the envelope is needed).
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
