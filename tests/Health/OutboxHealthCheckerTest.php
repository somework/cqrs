<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Health;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Outbox\OutboxMonitoring;
use SomeWork\CqrsBundle\Health\CheckResult;
use SomeWork\CqrsBundle\Health\CheckSeverity;
use SomeWork\CqrsBundle\Health\OutboxHealthChecker;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxStatus;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\BeforeQueryMiddleware;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CapableOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\OutboxRows;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;

use function array_map;
use function sprintf;

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
        OutboxRows::fail($this->storage, '00000000-0000-7000-8000-000000000002', 10, 'RuntimeException: boom', null);

        self::assertSame([
            [CheckSeverity::WARNING, 'The relay gave up on 1 outbox message(s); see "somework:cqrs:outbox:failed"'],
            [CheckSeverity::WARNING, '1 outbox message(s) are due, the oldest for 120 minute(s): "somework:cqrs:outbox:relay" does not run, does not keep up, or pauses their failing transport'],
        ], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_a_message_that_became_due_after_its_retry_delay_is_not_reported_as_waiting(): void
    {
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-5 minutes')));
        OutboxRows::fail($this->storage, '00000000-0000-7000-8000-000000000001', 3, 'RuntimeException: boom', new DateTimeImmutable('-5 seconds'));

        self::assertSame([[CheckSeverity::OK, 'Outbox: 1 message(s) due, none waiting for long']], self::summary((new OutboxHealthChecker($this->storage))->check()));
    }

    public function test_warns_about_messages_that_keep_failing_while_they_are_postponed(): void
    {
        // e.g. their transport is down: each run postpones them again, so they are never due for long.
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-2 days')));
        $this->storage->store(self::message('00000000-0000-7000-8000-000000000002', new DateTimeImmutable('-1 hour')));
        OutboxRows::fail($this->storage, '00000000-0000-7000-8000-000000000001', 20, 'TransportException: Connection refused', new DateTimeImmutable('+1 hour'));
        OutboxRows::fail($this->storage, '00000000-0000-7000-8000-000000000002', 1, 'TransportException: Connection refused', new DateTimeImmutable('+1 minute'));

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
            [CheckSeverity::WARNING, 'The outbox table needs "bin/console somework:cqrs:outbox:setup": the index "idx_somework_cqrs_outbox_pending" is missing; the index "idx_somework_cqrs_outbox_claimed" is missing; the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_a_table_of_version_04_is_a_warning(): void
    {
        // Messages stored by 0.4 wait; only the upgrade is due.
        $connection = TestDatabase::connect();
        TestDatabase::createTableOfVersion04($connection);
        $storage = new DbalOutboxStorage($connection);
        self::storeAsVersion04($connection, '00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-1 minute'));

        self::assertSame([
            [CheckSeverity::WARNING, 'The outbox table needs "bin/console somework:cqrs:outbox:setup": the columns attempts, available_at, failed_at, last_error, claim_token, claimed_at, signature are missing; the index "idx_somework_cqrs_outbox_pending" is missing; the index "idx_somework_cqrs_outbox_claimed" is missing; the index "idx_somework_cqrs_outbox_published_created" of version 0.4 is still there'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_a_table_structure_that_cannot_be_read_is_a_warning(): void
    {
        $platform = TestDatabase::connect()->getDatabasePlatform();
        $introspection = new BeforeQueryMiddleware(match (true) {
            $platform instanceof PostgreSQLPlatform => 'pg_namespace',
            $platform instanceof AbstractMySQLPlatform => 'information_schema',
            default => 'pragma_table_info',
        });
        $connection = TestDatabase::connect(null, [$introspection]);
        $storage = new DbalOutboxStorage($connection);
        $storage->setup();
        // e.g. missing privileges on the catalog; the messages themselves can still be read.
        $introspection->replacement = 'SELECT broken FROM nowhere';

        $results = self::summary((new OutboxHealthChecker(new DbalOutboxStorage($connection)))->check());

        self::assertSame(CheckSeverity::WARNING, $results[0][0]);
        self::assertStringStartsWith('The outbox table needs "bin/console somework:cqrs:outbox:setup": its structure cannot be read (', $results[0][1]);
    }

    public function test_messages_waiting_long_on_a_table_of_version_04_are_critical(): void
    {
        // e.g. the relay cannot add the columns (a compressed MySQL table, a busy table): nothing is sent until the setup.
        $connection = TestDatabase::connect();
        TestDatabase::createTableOfVersion04($connection);
        $storage = new DbalOutboxStorage($connection);
        self::storeAsVersion04($connection, '00000000-0000-7000-8000-000000000001', new DateTimeImmutable('-2 hours'));
        self::storeAsVersion04($connection, '00000000-0000-7000-8000-000000000002', new DateTimeImmutable('-1 minute'));

        $results = self::summary((new OutboxHealthChecker($storage))->check());

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0][0]);
        self::assertStringEndsWith('; 2 outbox message(s) wait, the oldest for 120 minute(s), and the relay cannot send them until then', $results[0][1]);
    }

    public function test_messages_that_cannot_be_read_are_critical_even_when_only_the_index_is_missing(): void
    {
        // e.g. the role lacks a privilege on the new columns: the upgrade is not the problem.
        $status = new BeforeQueryMiddleware('SELECT COUNT(*) FROM (SELECT');
        $connection = TestDatabase::connect(null, [$status]);
        TestDatabase::createTableOfVersion04($connection);
        (new DbalOutboxStorage($connection))->fetchUnpublished(1);
        $status->replacement = 'SELECT broken FROM nowhere';

        $results = self::summary((new OutboxHealthChecker(new DbalOutboxStorage($connection)))->check());

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0][0]);
    }

    public function test_an_unreachable_database_is_critical(): void
    {
        $down = new BeforeQueryMiddleware('');
        $connection = TestDatabase::connect(null, [$down]);
        // Every statement fails, e.g. the database is down.
        $down->replacement = 'SELECT broken FROM nowhere';

        $results = self::summary((new OutboxHealthChecker(new DbalOutboxStorage($connection)))->check());

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0][0]);
        self::assertStringStartsWith('The outbox storage cannot be read: ', $results[0][1]);
    }

    public function test_an_unreadable_storage_is_critical(): void
    {
        $storage = new DbalOutboxStorage(TestDatabase::connect(), autoSetup: false);

        $results = (new OutboxHealthChecker($storage))->check();

        self::assertCount(1, $results);
        self::assertSame(CheckSeverity::CRITICAL, $results[0]->severity);
        self::assertStringContainsString('The outbox storage cannot be read: The outbox table "somework_cqrs_outbox" does not exist.', $results[0]->message);
    }

    public function test_warns_about_a_claim_that_is_not_finished_for_long(): void
    {
        $storage = new CapableOutboxStorage();
        $storage->status = new OutboxStatus(due: 0, oldestDue: null, retrying: 0, oldestRetrying: null, failed: 0, inFlight: 3, oldestClaim: new DateTimeImmutable('-15 minutes'));

        self::assertSame([
            [CheckSeverity::WARNING, 'An outbox relay claimed messages 15 minute(s) ago and did not finish them (it died, or hangs on a send), and no relay has taken them over since their retry time: check that "somework:cqrs:outbox:relay" runs'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_capped_counts_are_reported_as_lower_bounds(): void
    {
        $storage = new CapableOutboxStorage();
        $storage->status = new OutboxStatus(due: OutboxStatus::COUNT_CAP, oldestDue: new DateTimeImmutable('-1 minute'), retrying: 0, oldestRetrying: null, failed: 3, capped: true);

        self::assertSame([
            [CheckSeverity::WARNING, 'The relay gave up on 3 outbox message(s); see "somework:cqrs:outbox:failed"'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));

        $storage->status = new OutboxStatus(due: OutboxStatus::COUNT_CAP, oldestDue: new DateTimeImmutable('-1 minute'), retrying: 0, oldestRetrying: null, failed: 0, capped: true);
        self::assertSame([[CheckSeverity::OK, 'Outbox: more than 10000 message(s) due, none waiting for long']], self::summary((new OutboxHealthChecker($storage))->check()));
    }

    public function test_storages_without_monitoring_are_not_checked(): void
    {
        self::assertSame([[CheckSeverity::OK, sprintf('The outbox storage (%s) is not checked: it does not implement %s', InMemoryOutboxStorage::class, OutboxMonitoring::class)]], self::summary((new OutboxHealthChecker(new InMemoryOutboxStorage()))->check()));
    }

    public function test_checks_any_storage_that_reports_its_status(): void
    {
        $storage = new CapableOutboxStorage();
        $storage->status = new OutboxStatus(due: 4, oldestDue: new DateTimeImmutable('-30 minutes'), retrying: 0, oldestRetrying: null, failed: 2);
        $storage->pendingChanges = ['the queue is missing'];

        self::assertSame([
            [CheckSeverity::WARNING, 'The outbox table needs "bin/console somework:cqrs:outbox:setup": the queue is missing'],
            [CheckSeverity::WARNING, 'The relay gave up on 2 outbox message(s); see "somework:cqrs:outbox:failed"'],
            [CheckSeverity::WARNING, '4 outbox message(s) are due, the oldest for 30 minute(s): "somework:cqrs:outbox:relay" does not run, does not keep up, or pauses their failing transport'],
        ], self::summary((new OutboxHealthChecker($storage))->check()));
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

    private static function storeAsVersion04(Connection $connection, string $id, DateTimeImmutable $createdAt): void
    {
        $connection->insert('somework_cqrs_outbox', ['id' => $id, 'body' => 'body', 'headers' => '{}', 'created_at' => $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }

    private static function message(string $id, DateTimeImmutable $createdAt): OutboxMessage
    {
        return new OutboxMessage($id, 'body', '{}', $createdAt);
    }
}
