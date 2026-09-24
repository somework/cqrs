<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxPurgeCommand;
use SomeWork\CqrsBundle\Contract\OutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\InMemoryOutboxStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use const DATE_ATOM;

#[CoversClass(OutboxPurgeCommand::class)]
final class OutboxPurgeCommandTest extends TestCase
{
    public function test_passes_the_cutoff_to_the_storage(): void
    {
        $storage = $this->createMock(OutboxStorage::class);
        $storage->expects(self::once())
            ->method('purgePublished')
            ->with(self::callback(static function (DateTimeImmutable $before): bool {
                $expected = new DateTimeImmutable('-12 hours');

                return abs($expected->getTimestamp() - $before->getTimestamp()) < 5;
            }))
            ->willReturn(3);

        $tester = new CommandTester(new OutboxPurgeCommand($storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '12 hours']));
        self::assertStringContainsString('Deleted 3 published message(s)', $tester->getDisplay());
    }

    public function test_deletes_published_messages_only(): void
    {
        $storage = new InMemoryOutboxStorage();
        $storage->store(new OutboxMessage('published', 'body', '{}', new DateTimeImmutable()));
        $storage->store(new OutboxMessage('pending', 'body', '{}', new DateTimeImmutable()));
        $storage->markPublished('published');

        $tester = new CommandTester(new OutboxPurgeCommand($storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '0 seconds']));
        self::assertSame(['pending'], array_map(static fn (OutboxMessage $message): string => $message->id, $storage->fetchUnpublished(10)));
        self::assertFalse($storage->isPublished('published'));
    }

    public function test_a_failing_storage_exits_with_1(): void
    {
        $storage = self::createStub(OutboxStorage::class);
        $storage->method('purgePublished')->willThrowException(new \RuntimeException('Connection refused'));

        $tester = new CommandTester(new OutboxPurgeCommand($storage));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('The outbox storage failed: Connection refused', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAges(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['whenever'];
        yield 'future' => ['-3 days'];
        yield 'bare number (a time zone offset for DateTime)' => ['7'];
        yield 'unknown unit' => ['7 fortnights'];
        yield 'relative expression' => ['last monday'];
        yield 'overflowing number (would become a future cut-off)' => ['99999999999999999999 days'];
    }

    public function test_a_very_old_cutoff_is_clamped_to_year_one(): void
    {
        $storage = $this->createMock(OutboxStorage::class);
        $storage->expects(self::once())
            ->method('purgePublished')
            ->with(self::callback(static fn (DateTimeImmutable $before): bool => '0001-01-01T00:00:00+00:00' === $before->format(DATE_ATOM)))
            ->willReturn(0);

        $tester = new CommandTester(new OutboxPurgeCommand($storage));

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '999999 years']));
    }

    #[DataProvider('invalidAges')]
    public function test_rejects_an_invalid_age(string $olderThan): void
    {
        $storage = $this->createMock(OutboxStorage::class);
        $storage->expects(self::never())->method('purgePublished');

        $tester = new CommandTester(new OutboxPurgeCommand($storage));

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => $olderThan]));
        self::assertStringContainsString('must be a relative age', $tester->getDisplay());
    }
}
