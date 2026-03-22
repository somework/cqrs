<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Handler\AbstractCommandHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(AbstractCommandHandler::class)]
final class AbstractCommandHandlerTest extends TestCase
{
    #[Test]
    public function invoke_delegates_to_handle(): void
    {
        $command = new CreateTaskCommand('id-1', 'Test');
        $handler = new class extends AbstractCommandHandler {
            public ?Command $received = null;

            protected function handle(Command $command): mixed
            {
                $this->received = $command;

                return 'command-result';
            }
        };

        $result = $handler($command);

        self::assertSame($command, $handler->received);
        self::assertSame('command-result', $result);
    }

    #[Test]
    public function handler_implements_envelope_aware(): void
    {
        $handler = new class extends AbstractCommandHandler {
            protected function handle(Command $command): mixed
            {
                return null;
            }
        };

        self::assertInstanceOf(EnvelopeAware::class, $handler); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function envelope_is_accessible_after_set(): void
    {
        $envelope = new Envelope(new \stdClass());
        $handler = new class extends AbstractCommandHandler {
            public ?Envelope $capturedEnvelope = null;

            protected function handle(Command $command): mixed
            {
                $this->capturedEnvelope = $this->getEnvelope();

                return null;
            }
        };

        $handler->setEnvelope($envelope);
        $handler(new CreateTaskCommand('id-1', 'Test'));

        self::assertSame($envelope, $handler->capturedEnvelope);
    }

    #[Test]
    public function get_envelope_throws_when_not_set(): void
    {
        $handler = new class extends AbstractCommandHandler {
            protected function handle(Command $command): mixed
            {
                return $this->getEnvelope();
            }
        };

        $this->expectException(\LogicException::class);

        $handler(new CreateTaskCommand('id-1', 'Test'));
    }
}
