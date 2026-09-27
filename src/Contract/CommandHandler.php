<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Marks a service as a command handler.
 *
 * Implement a public `__invoke()` method whose first parameter is type-hinted with the
 * concrete command class; the bundle registers the handler for that command. The
 * interface declares no method on purpose: PHP does not allow implementations to
 * narrow a parameter type, so a declared `__invoke(Command $command)` would forbid
 * `__invoke(CreateTask $command)`. Alternatively declare the command explicitly with
 * {@see \SomeWork\CqrsBundle\Attribute\AsCommandHandler}.
 *
 * Handlers SHOULD be stateless services and MUST NOT mutate the command.
 *
 * @template TCommand of Command
 *
 * @api
 */
interface CommandHandler
{
}
