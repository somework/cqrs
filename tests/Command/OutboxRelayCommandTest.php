<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxRelayCommand;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function array_map;
use function preg_replace;

#[CoversClass(OutboxRelayCommand::class)]
final class OutboxRelayCommandTest extends TestCase
{
    private InMemoryOutboxStorage $storage;

    private InMemoryTransport $async;

    private InMemoryTransport $events;

    private LockFactory $locks;

    /** @var list<object> */
    private array $handledInline = [];

    protected function setUp(): void
    {
        $this->storage = new InMemoryOutboxStorage();
        $this->async = new InMemoryTransport();
        $this->events = new InMemoryTransport();
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function test_reports_when_there_is_nothing_to_relay(): void
    {
        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No unpublished messages found.', self::display($tester));
    }

    public function test_relays_messages_in_order_to_their_stored_transport(): void
    {
        $this->store(new CreateTaskCommand('1', 'first'), 'async');
        $this->store(new CreateTaskCommand('2', 'second'), 'async');

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Relayed 2 message(s).', self::display($tester));
        self::assertSame(['1', '2'], array_map(static fn (Envelope $envelope): string => self::taskId($envelope), $this->async->getSent()));
        self::assertSame([], $this->storage->fetchUnpublished(10));
    }

    public function test_messages_without_transport_name_follow_the_routing(): void
    {
        $this->store(new TaskCreatedEvent('task-1'));

        $this->execute();

        self::assertCount(1, $this->events->getSent());
        self::assertSame([], $this->async->getSent());
    }

    public function test_warns_when_a_message_was_handled_instead_of_sent(): void
    {
        $this->store(new \stdClass());

        $tester = $this->execute();

        self::assertCount(1, $this->handledInline);
        self::assertStringContainsString('was not sent to any transport', self::display($tester));
    }

    public function test_failures_do_not_stop_the_run_and_stay_unpublished(): void
    {
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');
        $this->storage->store(new OutboxMessage('broken', 'not a serialized envelope', '{}', new DateTimeImmutable()));
        $this->store(new CreateTaskCommand('2', 'ok'), 'async');

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to relay message "broken"', self::display($tester));
        self::assertCount(2, $this->async->getSent());
        self::assertSame(['broken'], array_map(static fn (OutboxMessage $message): string => $message->id, $this->storage->fetchUnpublished(10)));
    }

    /**
     * Symfony 8 serializers return decoding failures inside the envelope instead of throwing.
     *
     * @return iterable<string, array{Envelope}>
     */
    public static function envelopesReportingADecodingFailure(): iterable
    {
        yield 'wrapped exception' => [new Envelope(new MessageDecodingFailedException('Could not decode Envelope.'))];
        yield 'decoding failed stamp' => [new Envelope(new \stdClass(), [new MessageDecodingFailedStamp()])];
    }

    #[DataProvider('envelopesReportingADecodingFailure')]
    public function test_a_decoding_failure_reported_in_the_envelope_is_a_failure(Envelope $decoded): void
    {
        $this->storage->store(new OutboxMessage('undecodable', 'body', '{}', new DateTimeImmutable()));
        $serializer = self::createStub(SerializerInterface::class);
        $serializer->method('decode')->willReturn($decoded);

        $tester = new CommandTester(new OutboxRelayCommand($this->storage, $serializer, $this->bus(), $this->locks));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to relay message "undecodable"', self::display($tester));
        self::assertFalse($this->storage->isPublished('undecodable'));
        self::assertSame([], $this->handledInline);
    }

    public function test_failing_messages_at_the_head_do_not_block_the_queue(): void
    {
        foreach (['p1', 'p2', 'p3'] as $id) {
            $this->storage->store(new OutboxMessage($id, 'not a serialized envelope', '{}', new DateTimeImmutable('2026-01-01')));
        }
        $this->store(new CreateTaskCommand('1', 'ok'), 'async');
        $this->store(new CreateTaskCommand('2', 'ok'), 'async');

        $tester = $this->execute(['--limit' => '2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertCount(2, $this->async->getSent());
    }

    public function test_limit_bounds_the_number_of_relayed_messages(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->store(new CreateTaskCommand('2', 'b'), 'async');
        $this->store(new CreateTaskCommand('3', 'c'), 'async');

        $this->execute(['--limit' => '2']);

        self::assertCount(2, $this->async->getSent());
        self::assertCount(1, $this->storage->fetchUnpublished(10));
    }

    public function test_a_message_that_cannot_be_marked_published_is_reported(): void
    {
        $message = $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $this->storage->failMarkingPublished = [$message->id];

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Cannot mark', self::display($tester));
    }

    public function test_rejects_an_invalid_limit(): void
    {
        foreach (['0', '-5', 'abc'] as $limit) {
            $tester = $this->execute(['--limit' => $limit]);

            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString('Limit must be a positive integer.', self::display($tester));
        }
    }

    public function test_skips_when_another_relay_is_running(): void
    {
        $this->store(new CreateTaskCommand('1', 'a'), 'async');
        $lock = $this->locks->createLock('somework:cqrs:outbox:relay');
        $lock->acquire();

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Another outbox relay is already running.', self::display($tester));
        self::assertSame([], $this->async->getSent());
    }

    private function store(object $message, ?string $transportName = null): OutboxMessage
    {
        $outboxMessage = OutboxMessage::fromEnvelope(new Envelope($message), new PhpSerializer(), $transportName);
        $this->storage->store($outboxMessage);

        return $outboxMessage;
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(array $input = []): CommandTester
    {
        $tester = new CommandTester(new OutboxRelayCommand($this->storage, new PhpSerializer(), $this->bus(), $this->locks));
        $tester->execute($input);

        return $tester;
    }

    private function bus(): MessageBus
    {
        $senders = new SendersLocator(
            [TaskCreatedEvent::class => ['events']],
            new ServiceLocator(['async' => fn (): InMemoryTransport => $this->async, 'events' => fn (): InMemoryTransport => $this->events]),
        );

        return new MessageBus([
            new SendMessageMiddleware($senders),
            new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [function (object $message): void {
                $this->handledInline[] = $message;
            }]]), true),
        ]);
    }

    /**
     * SymfonyStyle wraps long lines; collapse whitespace so assertions do not depend on the terminal width.
     */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function taskId(Envelope $envelope): string
    {
        $message = $envelope->getMessage();
        self::assertInstanceOf(CreateTaskCommand::class, $message);

        return $message->id;
    }
}
