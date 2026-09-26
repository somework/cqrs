<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\ConsoleRelayReporter;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(ConsoleRelayReporter::class)]
final class ConsoleRelayReporterTest extends TestCase
{
    public function test_control_characters_and_tags_in_a_message_id_do_not_reach_the_terminal(): void
    {
        // A custom storage, or a row written by hand, may hold any id.
        $message = new OutboxMessage("id\e]0;title\x07<error>x</error>", 'body', '{}', new DateTimeImmutable());
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $reporter = new ConsoleRelayReporter(new SymfonyStyle(new ArrayInput([]), $output), static fn (): bool => true, static fn (): bool => false);

        $reporter->attemptFailed($message, 1, 10, new DateTimeImmutable(), 'boom');
        $reporter->claimedElsewhereAfterFailure($message, 'boom');
        $reporter->gaveUp($message, 10, 'boom');

        $display = $output->fetch();
        self::assertStringNotContainsString("\e]", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertSame(3, substr_count($display, 'id ]0;title <error>x</error>'), 'The id is shown, with its tags as text.');
    }
}
