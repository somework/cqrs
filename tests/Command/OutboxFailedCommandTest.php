<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxFailedCommand;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function preg_replace;

#[CoversClass(OutboxFailedCommand::class)]
final class OutboxFailedCommandTest extends TestCase
{
    private const ID_1 = '00000000-0000-7000-8000-000000000001';

    private const ID_2 = '00000000-0000-7000-8000-000000000002';

    private DbalOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new DbalOutboxStorage(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        foreach ([self::ID_1, self::ID_2] as $id) {
            $this->storage->store(new OutboxMessage($id, 'body', '{}', new DateTimeImmutable('2026-01-01 10:00:00+00:00'), 'async'));
            $this->storage->markFailed($id, 3, 'RuntimeException: Connection refused', null);
        }
    }

    public function test_lists_the_messages_the_relay_gave_up_on(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = self::display($tester);
        self::assertStringContainsString(self::ID_1, $display);
        self::assertStringContainsString(self::ID_2, $display);
        self::assertStringContainsString('2026-01-01T10:00:00+00:00', $display);
        self::assertStringContainsString('RuntimeException: Connection refused', $display);
        self::assertStringContainsString('--requeue', $display);
    }

    public function test_requeues_the_given_messages(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => [self::ID_2], '--requeue' => true]));
        self::assertStringContainsString('Requeued 1 message(s)', self::display($tester));
        self::assertSame([self::ID_2], array_map(static fn (OutboxMessage $message): string => $message->id, $this->storage->fetchUnpublished(10)));
    }

    public function test_requeues_every_given_up_message(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['--requeue' => true]));
        self::assertStringContainsString('Requeued 2 message(s)', self::display($tester));
        self::assertCount(2, $this->storage->fetchUnpublished(10));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The relay has not given up on any message.', self::display($tester));
    }

    public function test_rejects_ids_without_requeue_and_an_invalid_limit(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::INVALID, $tester->execute(['ids' => [self::ID_1]]));
        self::assertStringContainsString('only accepted together with --requeue', self::display($tester));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('Limit must be a positive integer.', self::display($tester));
    }

    public function test_rejects_ids_that_are_not_uuids(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::INVALID, $tester->execute(['ids' => ['42'], '--requeue' => true]));
        self::assertStringContainsString('"42" is not an outbox message id (a UUID).', self::display($tester));
    }

    public function test_reports_ids_that_were_not_requeued(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => [self::ID_1, '00000000-0000-7000-8000-00000000abcd'], '--requeue' => true]));
        self::assertStringContainsString('Requeued 1 message(s)', self::display($tester));
        self::assertStringContainsString('1 of the 2 given message(s) were not requeued', self::display($tester));
    }

    public function test_a_failing_storage_exits_with_1(): void
    {
        $storage = new DbalOutboxStorage(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), autoSetup: false);
        $tester = new CommandTester(new OutboxFailedCommand($storage));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('The outbox storage failed: The outbox table "somework_cqrs_outbox" does not exist.', self::display($tester));
    }

    public function test_requires_the_dbal_storage(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand(new InMemoryOutboxStorage()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('is not the DBAL storage', self::display($tester));
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
