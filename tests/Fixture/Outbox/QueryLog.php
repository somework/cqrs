<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Psr\Log\AbstractLogger;

use function is_string;

/**
 * Collects the SQL of DBAL's logging middleware (see TestDatabase::connect()).
 */
final class QueryLog extends AbstractLogger
{
    /** @var list<string> */
    private array $sql = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (is_string($context['sql'] ?? null)) {
            $this->sql[] = $context['sql'];
        }
    }

    /**
     * Returns the statements logged since the last call.
     *
     * @return list<string>
     */
    public function flush(): array
    {
        [$sql, $this->sql] = [$this->sql, []];

        return $sql;
    }
}
