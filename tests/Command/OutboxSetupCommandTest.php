<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\OutboxSetupCommand;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OutboxSetupCommand::class)]
final class OutboxSetupCommandTest extends TestCase
{
    public function test_creates_the_table_and_is_idempotent(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tester = new CommandTester(new OutboxSetupCommand(new DbalOutboxStorage($connection, 'outbox', autoSetup: false)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($connection->createSchemaManager()->tablesExist(['outbox']));
        self::assertStringContainsString('The outbox table is ready.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }
}
