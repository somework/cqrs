<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

/**
 * An outbox storage that can create and upgrade its own schema; "somework:cqrs:outbox:setup",
 * the health check and the relay use it. A storage whose schema is managed elsewhere (e.g. by
 * migrations only) does not implement it.
 *
 * @api
 */
interface OutboxSchema
{
    /**
     * Creates the schema, or brings an existing one up to date.
     *
     * @param (\Closure(): void)|null $onWait Called once before waiting for another process that is setting up the schema
     *
     * @throws \Throwable when the schema cannot be set up; the message says why
     */
    public function setup(?\Closure $onWait = null): void;

    /**
     * What setup() still has to change, as sentences for an operator (e.g. "the index "x" is
     * missing"); empty when the schema is up to date.
     *
     * @return list<string>
     */
    public function pendingChanges(): array;
}
