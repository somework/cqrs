<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Command\DebugTransportsCommand;
use SomeWork\CqrsBundle\Support\TransportMappingProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugTransportsCommandTest extends TestCase
{
    public function test_displays_transport_mapping_table(): void
    {
        $provider = new TransportMappingProvider([
            'command' => [
                'default' => ['sync_transport'],
                'map' => [
                    DebugTransportCommandMessage::class => ['async_transport'],
                ],
            ],
            'command_async' => [
                'default' => ['async_transport'],
                'map' => [],
            ],
            'query' => [
                'default' => [],
                'map' => [
                    DebugTransportQueryMessage::class => ['slow_transport', 'fast_transport'],
                ],
            ],
            'event' => [
                'default' => ['broadcast'],
                'map' => [],
            ],
            'event_async' => [
                'default' => [],
                'map' => [],
            ],
        ]);

        $tester = new CommandTester(new DebugTransportsCommand($provider));

        $exitCode = $tester->execute([]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);

        $output = $tester->getDisplay();

        self::assertStringContainsString('Command', $output);
        self::assertStringContainsString('Command (async)', $output);
        self::assertStringContainsString('Query', $output);
        self::assertStringContainsString('Event', $output);
        self::assertStringContainsString('Event (async)', $output);

        self::assertStringContainsString('sync_transport', $output);
        self::assertStringContainsString('async_transport', $output);
        self::assertStringContainsString('DebugTransportCommandMessage => async_transport', $output);
        self::assertStringContainsString('DebugTransportQueryMessage => slow_transport, fast_transport', $output);
        self::assertStringContainsString('None', $output);
    }
}

final class DebugTransportCommandMessage
{
}

final class DebugTransportQueryMessage
{
}
