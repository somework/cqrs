<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use const SIGINT;
use const SIGTERM;

#[Group('database')]
#[CoversClass(OutboxSetupCommand::class)]
final class OutboxSetupCommandTest extends TestCase
{
    public function test_creates_the_table_and_is_idempotent(): void
    {
        $connection = TestDatabase::connect();
        $tester = new CommandTester(new OutboxSetupCommand(new DbalOutboxStorage($connection, 'outbox', autoSetup: false)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($connection->createSchemaManager()->tablesExist(['outbox']));
        self::assertStringContainsString('The outbox table is ready.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function test_a_table_that_cannot_be_created_exits_with_1(): void
    {
        $connection = TestDatabase::connect();
        $connection->beginTransaction();
        $tester = new CommandTester(new OutboxSetupCommand(new DbalOutboxStorage($connection, 'outbox', autoSetup: false)));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('cannot be changed inside an open database transaction', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function test_a_signal_stops_the_setup_with_a_failure(): void
    {
        // e.g. a deploy job that is terminated while the setup waits for another one: the
        // deployment must not go on as if the table was ready.
        $command = new OutboxSetupCommand(new DbalOutboxStorage(TestDatabase::connect(), 'outbox', autoSetup: false));
        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(128 + 15, $command->handleSignal(15));
        self::assertStringContainsString('The outbox table setup was stopped by signal 15; run it again.', $tester->getErrorOutput());
    }

    #[RequiresPhpExtension('pcntl')]
    public function test_subscribes_to_the_termination_signals(): void
    {
        $command = new OutboxSetupCommand(new DbalOutboxStorage(TestDatabase::connect(), 'outbox', autoSetup: false));

        self::assertSame([SIGTERM, SIGINT], $command->getSubscribedSignals());
    }
}
