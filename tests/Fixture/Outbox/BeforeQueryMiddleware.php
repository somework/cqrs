<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Outbox;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

use function str_contains;

/**
 * Runs a callback once, right before the first statement whose SQL contains $needle, e.g. to let
 * another process change a row between two queries. With a $replacement, every such statement is
 * replaced by it (e.g. to fake what the database reports).
 *
 * The driver and its connections delegate to the wrapped ones instead of extending DBAL's
 * AbstractDriverMiddleware and AbstractConnectionMiddleware, which DBAL 5 declares readonly.
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
        return new class($driver, $this) implements Driver {
            public function __construct(private readonly Driver $driver, private readonly BeforeQueryMiddleware $middleware)
            {
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class($this->driver->connect($params), $this->middleware) implements DriverConnection {
                    public function __construct(private readonly DriverConnection $connection, private readonly BeforeQueryMiddleware $middleware)
                    {
                    }

                    public function prepare(string $sql): Statement
                    {
                        return $this->connection->prepare($this->middleware->before($sql));
                    }

                    public function query(string $sql): Result
                    {
                        return $this->connection->query($this->middleware->before($sql));
                    }

                    public function quote(string $value): string
                    {
                        return $this->connection->quote($value);
                    }

                    public function exec(string $sql): int|string
                    {
                        return $this->connection->exec($sql);
                    }

                    public function lastInsertId(): int|string
                    {
                        return $this->connection->lastInsertId();
                    }

                    public function beginTransaction(): void
                    {
                        $this->connection->beginTransaction();
                    }

                    public function commit(): void
                    {
                        $this->connection->commit();
                    }

                    public function rollBack(): void
                    {
                        $this->connection->rollBack();
                    }

                    public function getServerVersion(): string
                    {
                        return $this->connection->getServerVersion();
                    }

                    public function getNativeConnection(): mixed
                    {
                        return $this->connection->getNativeConnection();
                    }
                };
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->driver->getDatabasePlatform($versionProvider);
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                return $this->driver->getExceptionConverter();
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
