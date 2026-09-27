<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

use function str_contains;

/**
 * Runs a callback once, right before the first statement whose SQL contains $needle, e.g. to let
 * another process change a row between two queries. With a $replacement, every such statement is
 * replaced by it (e.g. to fake what the database reports).
 */
final class BeforeQueryMiddleware implements Middleware
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $callback = null;

    public ?string $replacement = null;

    public function __construct(private readonly string $needle)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly BeforeQueryMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class(parent::connect($params), $this->middleware) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly BeforeQueryMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return parent::prepare($this->middleware->before($sql));
                    }

                    public function query(string $sql): Result
                    {
                        return parent::query($this->middleware->before($sql));
                    }
                };
            }
        };
    }

    public function before(string $sql): string
    {
        if (!str_contains($sql, $this->needle)) {
            return $sql;
        }

        if (null !== $this->callback) {
            $callback = $this->callback;
            $this->callback = null;
            $callback();
        }

        return $this->replacement ?? $sql;
    }
}
