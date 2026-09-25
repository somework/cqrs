<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\OutboxHealthChecker;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;

use function array_map;

#[Group('database')]
#[CoversClass(OutboxHealthChecker::class)]
final class OutboxHealthCheckerTest extends TestCase
{
    private DbalOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new DbalOutboxStorage(TestDatabase::connect());
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
            [CheckSeverity::WARNING, '1 outbox message(s) are due, the oldest for 120 minute(s): "somework:cqrs:outbox:relay" does not run, does not keep up, or pauses their failing transport'],
        ], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_a_message_that_became_due_after_its_retry_delay_is_not_reported_as_waiting(): void
    {
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-5 minutes')));
        $this->storage->recordAttempt('00000000-0000-7000-8000-000000000001', 3, 'RuntimeException: boom', new DateTimeImmutable('-5 seconds'));

        self::assertSame([[CheckSeverity::OK, 'Outbox: 1 message(s) due, none waiting for long']], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_warns_about_messages_that_keep_failing_while_they_are_postponed(): void
    {
        // e.g. their transport is down: each run postpones them again, so they are never due for long.
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-2 days')));
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000002', new DateTimeImmutable('-1 hour')));
        $this->storage->recordAttempt('00000000-0000-7000-8000-000000000001', 20, 'TransportException: Connection refused', new DateTimeImmutable('+1 hour'));
        $this->storage->recordAttempt('00000000-0000-7000-8000-000000000002', 1, 'TransportException: Connection refused', new DateTimeImmutable('+1 minute'));

        self::assertSame([
            [CheckSeverity::WARNING, '2 outbox message(s) failed and wait for another attempt, the oldest was stored 2880 minute(s) ago; see the relay output or the "last_error" column'],
        ], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_warns_about_a_table_that_needs_the_setup_command(): void
    {
        $connection = TestDatabase::connect();
        TestDatabase::createTableOfVersion04($connection);
        $storage = new DbalOutboxStorage($connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-1 minute')));
        // The relay's automatic setup adds the columns, not the index.
        $storage->fetchUnpublished(1);

        self::assertSame([
            [CheckSeverity::WARNING, 'The outbox table needs "bin/console somework:cqrs:outbox:setup": the index "idx_somework_cqrs_outbox_pending" is missing; the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_a_table_of_version_04_is_a_warning(): void
    {
        // Messages are still stored (store() never changes the table); only the upgrade is due.
        $connection = TestDatabase::connect();
        TestDatabase::createTableOfVersion04($connection);
        $storage = new DbalOutboxStorage($connection);
        $storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-1 minute')));

        self::assertSame([
            [CheckSeverity::WARNING, 'The outbox table needs "bin/console somework:cqrs:outbox:setup": the columns attempts, available_at, failed_at, last_error are missing; the index "idx_somework_cqrs_outbox_pending" is missing; the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_an_unreadable_storage_is_critical(): void
    {
        $storage = new DbalOutboxStorage(TestDatabase::connect(), autoSetup: false);

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
