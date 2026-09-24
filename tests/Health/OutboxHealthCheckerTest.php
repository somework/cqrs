<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\OutboxHealthChecker;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;

use function array_map;

#[CoversClass(OutboxHealthChecker::class)]
final class OutboxHealthCheckerTest extends TestCase
{
    private DbalOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new DbalOutboxStorage(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
    }

    public function test_a_relay_that_keeps_up_is_ok(): void
    {
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-1 minute')));

        self::assertSame([[CheckSeverity::OK, 'Outbox: 1 message(s) due, none waiting for long']], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_warns_about_given_up_and_long_waiting_messages(): void
    {
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-2 hours')));
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000002', new DateTimeImmutable('-1 hour')));
        $this->storage->recordAttempt('00000000-0000-7000-8000-000000000002', 10, 'RuntimeException: boom', null);

        self::assertSame([
            [CheckSeverity::WARNING, 'The relay gave up on 1 outbox message(s); see "somework:cqrs:outbox:failed"'],
            [CheckSeverity::WARNING, '1 outbox message(s) are due, the oldest for 120 minute(s): is "somework:cqrs:outbox:relay" running?'],
        ], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_a_message_that_became_due_after_its_retry_delay_is_not_reported_as_waiting(): void
    {
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-2 hours')));
        $this->storage->recordAttempt('00000000-0000-7000-8000-000000000001', 3, 'RuntimeException: boom', new DateTimeImmutable('-5 seconds'));

        self::assertSame([[CheckSeverity::OK, 'Outbox: 1 message(s) due, none waiting for long']], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_an_unreadable_storage_is_critical(): void
    {
        $storage = new DbalOutboxStorage(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), autoSetup: false);

        $results = (new OutboxHealthChecker($storage))->check();

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0]->severity);
        self::assertStringContainsString('The outbox storage cannot be read: The outbox table "somework_cqrs_outbox" does not exist.', $results[0]->message);
    }

    public function test_other_storages_are_not_checked(): void
    {
        self::assertSame([CheckSeverity::OK], array_map(static fn (CheckResult $result): CheckSeverity => $result->severity, (new OutboxHealthChecker(new InMemoryOutboxStorage()))->check()));
    }

    /**
     * @param list<CheckResult> $results
     *
     * @return list<array{CheckSeverity, string}>
     */
    private static function summary(array $results): array
    {
        return array_map(static fn (CheckResult $result): array => [$result->severity, $result->message], $results);
    }

    private static function message(string $id, DateTimeImmutable $createdAt): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{}', $createdAt);
    }
}
