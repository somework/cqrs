<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * @template TCommand of Command
 */
interface CommandHandler
{
    /**
     * Handle the given command.
     *
     * Implementations SHOULD be stateless services. They MUST NOT mutate the
     * provided command instance.
     *
     * @param TCommand $command
     */
    public function __invoke(Command $command): mixed;
}
