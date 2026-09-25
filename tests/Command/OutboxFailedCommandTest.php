<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxFailedCommand;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\FailedOutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\Signing\OutboxSigner;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\CapableOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\OutboxRows;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function array_map;
use function json_encode;
use function preg_replace;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

#[Group('database')]
#[CoversClass(OutboxFailedCommand::class)]
final class OutboxFailedCommandTest extends TestCase
{
    private const ID_1 = '00000000-0000-7000-8000-000000000001';

    private const ID_2 = '00000000-0000-7000-8000-000000000002';

    private DbalOutboxStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new DbalOutboxStorage(TestDatabase::connect());

        foreach ([self::ID_1, self::ID_2] as $id) {
            $this->storage->store(new OutboxMessage($id, 'body', '{}', new DateTimeImmutable('2026-01-01 10:00:00+00:00'), 'async'));
            OutboxRows::fail($this->storage, $id, 3, 'RuntimeException: Connection refused', null);
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

    public function test_signing_shows_the_class_in_the_body_and_refuses_a_misleading_type_header(): void
    {
        $encoded = OutboxMessage::fromEnvelope(new Envelope(new CreateTaskCommand('1', 'a')), new PhpSerializer(), 'async');
        // A forged row: a harmless-looking type header in front of another class.
        $forged = '00000000-0000-7000-8000-000000000003';
        $this->storage->store(new OutboxMessage($forged, $encoded->body, json_encode(['type' => 'App\Message\Harmless'], JSON_THROW_ON_ERROR), new DateTimeImmutable(), 'async'));
        OutboxRows::fail($this->storage, $forged, 1, 'not signed', null);
        $genuine = '00000000-0000-7000-8000-000000000004';
        $this->storage->store(new OutboxMessage($genuine, $encoded->body, $encoded->headers, new DateTimeImmutable(), 'async'));
        OutboxRows::fail($this->storage, $genuine, 1, 'not signed', null);
        $signer = new OutboxSigner('secret');

        $refused = new CommandTester(new OutboxFailedCommand($this->storage, $signer));
        self::assertSame(Command::FAILURE, $refused->execute(['--requeue' => true, '--sign' => true, 'ids' => [$forged]], ['interactive' => false]));
        self::assertStringContainsString('does not match the class in its body ('.CreateTaskCommand::class.')', self::display($refused));
        self::assertSame([$forged], array_map(static fn (FailedOutboxMessage $message): string => $message->id, $this->storage->fetchFailed(10, [$forged])), 'Nothing was requeued.');

        $signed = new CommandTester(new OutboxFailedCommand($this->storage, $signer));
        self::assertSame(Command::SUCCESS, $signed->execute(['--requeue' => true, '--sign' => true, 'ids' => [$genuine]], ['interactive' => false]));
        self::assertStringContainsString(CreateTaskCommand::class, self::display($signed));
        self::assertMatchesRegularExpression('/sha256 [0-9a-f]{16}/', self::display($signed));
        $requeued = OutboxRows::due($this->storage, $genuine);
        self::assertTrue($signer->verify($requeued));
    }

    public function test_requeues_to_another_transport(): void
    {
        // e.g. a row stored with a misspelt or renamed transport, which the relay gave up on.
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['--requeue' => true, '--transport' => 'orders', 'ids' => [self::ID_1]]));
        self::assertStringContainsString('Requeued 1 message(s) to the transport "orders"', self::display($tester));

        $due = $this->storage->fetchUnpublished(10);
        self::assertCount(1, $due);
        self::assertSame('orders', $due[0]->transportName);
        self::assertSame(0, $due[0]->attempts);
    }

    public function test_a_transport_needs_requeue_and_a_name(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::INVALID, $tester->execute(['--transport' => 'orders']));
        self::assertSame(Command::INVALID, $tester->execute(['--requeue' => true, '--transport' => ' ']));
        self::assertStringContainsString('--transport needs a transport name and --requeue.', self::display($tester));
    }

    public function test_stored_text_is_listed_without_control_characters(): void
    {
        // A row written by someone else could put escape sequences into the terminal of the operator.
        $this->storage->store(new OutboxMessage('00000000-0000-7000-8000-000000000003', 'body', '{}', new DateTimeImmutable(), "as\e[2Jync"));
        OutboxRows::fail($this->storage, '00000000-0000-7000-8000-000000000003', 3, "boom \e]8;;http://evil.example\e\\", null);
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString("\e", $tester->getDisplay());
        self::assertStringContainsString(']8;;http://evil.example', $tester->getDisplay());
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

    public function test_ids_are_matched_case_insensitively(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand($this->storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => [strtoupper(self::ID_1)], '--requeue' => true]));
        self::assertStringContainsString('Requeued 1 message(s)', self::display($tester));
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
        $storage = new DbalOutboxStorage(TestDatabase::connect(), autoSetup: false);
        $tester = new CommandTester(new OutboxFailedCommand($storage));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('The outbox storage failed: The outbox table "somework_cqrs_outbox" does not exist.', self::display($tester));
    }

    public function test_requires_a_storage_that_lists_failed_messages(): void
    {
        $tester = new CommandTester(new OutboxFailedCommand(new InMemoryOutboxStorage()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('does not implement SomeWork\\CqrsBundle\\Contract\\Outbox\\FailedOutboxMessages', self::display($tester));
    }

    public function test_works_with_any_storage_that_lists_failed_messages(): void
    {
        $storage = new CapableOutboxStorage();
        $storage->failed = [new FailedOutboxMessage('0199a000-0000-7000-8000-000000000001', null, new DateTimeImmutable('2026-01-01 10:00:00+00:00'), new DateTimeImmutable('2026-01-01 11:00:00+00:00'), 3, "boom\e[31m")];

        $tester = new CommandTester(new OutboxFailedCommand($storage));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0199a000-0000-7000-8000-000000000001 ? (routing) 2026-01-01T10:00:00+00:00 2026-01-01T11:00:00+00:00 3 boom [31m', self::display($tester));

        self::assertSame(Command::SUCCESS, (new CommandTester(new OutboxFailedCommand($storage)))->execute(['--requeue' => true, '--transport' => 'async']));
        self::assertSame([[[], 'async', null]], $storage->requeued);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
