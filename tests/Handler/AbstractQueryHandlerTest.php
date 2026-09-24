<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Handler\AbstractQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;

#[CoversClass(AbstractQueryHandler::class)]
final class AbstractQueryHandlerTest extends TestCase
{
    public function test_invoke_delegates_to_fetch(): void
    {
        $query = new FindTaskQuery('task-1');
        $handler = new class extends AbstractQueryHandler {
            public ?Query $received = null;

            protected function fetch(Query $query): mixed
            {
                $this->received = $query;

                return ['task-name'];
            }
        };

        $result = $handler($query);

        self::assertSame($query, $handler->received);
        self::assertSame(['task-name'], $result);
    }

    public function test_handler_implements_envelope_aware(): void
    {
        $handler = new class extends AbstractQueryHandler {
            protected function fetch(Query $query): mixed
            {
                return null;
            }
        };

        self::assertInstanceOf(EnvelopeAware::class, $handler); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    public function test_invoke_returns_null_when_fetch_returns_null(): void
    {
        $handler = new class extends AbstractQueryHandler {
            protected function fetch(Query $query): mixed
            {
                return null;
            }
        };

        $result = $handler(new FindTaskQuery('task-1'));

        self::assertNull($result);
    }
}
