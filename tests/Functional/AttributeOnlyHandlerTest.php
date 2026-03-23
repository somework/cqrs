<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Functional;

use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\AttributeOnlyQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Kernel\AttributeOnlyTestKernel;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Message\PlainQuery;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

use function assert;
use function is_array;

final class AttributeOnlyHandlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return AttributeOnlyTestKernel::class;
    }

    public function test_attribute_only_command_handler_in_metadata(): void
    {
        $metadata = $this->getHandlerMetadata();

        $commandMessages = array_column($metadata['command'], 'message');
        self::assertContains(PlainCommand::class, $commandMessages);

        $entry = $this->findMetadataEntry($metadata['command'], PlainCommand::class);
        self::assertNotNull($entry);
        self::assertSame('command', $entry['type']);
        self::assertSame(AttributeOnlyCommandHandler::class, $entry['handler_class']);
    }

    public function test_attribute_only_query_handler_in_metadata(): void
    {
        $metadata = $this->getHandlerMetadata();

        $queryMessages = array_column($metadata['query'], 'message');
        self::assertContains(PlainQuery::class, $queryMessages);

        $entry = $this->findMetadataEntry($metadata['query'], PlainQuery::class);
        self::assertNotNull($entry);
        self::assertSame('query', $entry['type']);
        self::assertSame(AttributeOnlyQueryHandler::class, $entry['handler_class']);
    }

    public function test_attribute_only_event_handler_in_metadata(): void
    {
        $metadata = $this->getHandlerMetadata();

        $eventMessages = array_column($metadata['event'], 'message');
        self::assertContains(PlainEvent::class, $eventMessages);

        $entry = $this->findMetadataEntry($metadata['event'], PlainEvent::class);
        self::assertNotNull($entry);
        self::assertSame('event', $entry['type']);
        self::assertSame(AttributeOnlyEventHandler::class, $entry['handler_class']);
    }

    public function test_attribute_only_handler_appears_in_list_command(): void
    {
        $kernel = self::$kernel;
        assert(null !== $kernel);

        $application = new Application($kernel);
        $command = $application->find('somework:cqrs:list');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay(true);

        self::assertStringContainsString('PlainCommand', $display);
        self::assertStringContainsString('AttributeOnlyCommandHandler', $display);
        self::assertStringContainsString('PlainQuery', $display);
        self::assertStringContainsString('PlainEvent', $display);
    }

    public function test_interface_handler_still_works_alongside(): void
    {
        $metadata = $this->getHandlerMetadata();

        // Interface-based handler still present
        $commandMessages = array_column($metadata['command'], 'message');
        self::assertContains(CreateTaskCommand::class, $commandMessages);

        $interfaceEntry = $this->findMetadataEntry($metadata['command'], CreateTaskCommand::class);
        self::assertNotNull($interfaceEntry);
        self::assertSame(CreateTaskHandler::class, $interfaceEntry['handler_class']);

        // Attribute-only handler also present
        self::assertContains(PlainCommand::class, $commandMessages);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function getHandlerMetadata(): array
    {
        $metadata = static::getContainer()->getParameter('somework_cqrs.handler_metadata');
        assert(is_array($metadata));

        /* @var array<string, list<array<string, mixed>>> $metadata */
        return $metadata;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>|null
     */
    private function findMetadataEntry(array $entries, string $messageClass): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['message'] === $messageClass) {
                return $entry;
            }
        }

        return null;
    }
}
