<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Bus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\CommandBus;
use SomeWork\CqrsBundle\Bus\QueryBus;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/**
 * dispatchSync() and ask() through real Messenger buses.
 */
#[CoversClass(CommandBus::class)]
#[CoversClass(QueryBus::class)]
final class SynchronousDispatchTest extends TestCase
{
    public function test_dispatch_sync_rethrows_the_handler_exception(): void
    {
        $bus = new CommandBus($this->bus([
            CreateTaskCommand::class => [static fn (CreateTaskCommand $command): never => throw new \DomainException('Task already exists')],
        ]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Task already exists');

        $bus->dispatchSync(new CreateTaskCommand('1', 'x'));
    }

    public function test_ask_rethrows_the_handler_exception(): void
    {
        $bus = new QueryBus($this->bus([
            FindTaskQuery::class => [static fn (FindTaskQuery $query): never => throw new \OutOfBoundsException('Unknown task')],
        ]), StampsDecider::withoutDecorators());

        $this->expectException(\OutOfBoundsException::class);

        $bus->ask(new FindTaskQuery('1'));
    }

    public function test_a_message_without_handler_raises_no_handler_exception(): void
    {
        try {
            (new CommandBus($this->bus([])))->dispatchSync(new CreateTaskCommand('1', 'x'));
            self::fail('Expected a NoHandlerException.');
        } catch (NoHandlerException $exception) {
            self::assertSame(CreateTaskCommand::class, $exception->messageFqcn);
            self::assertInstanceOf(NoHandlerForMessageException::class, $exception->getPrevious());
        }

        $this->expectException(NoHandlerException::class);
        (new QueryBus($this->bus([]), StampsDecider::withoutDecorators()))->ask(new FindTaskQuery('1'));
    }

    public function test_a_missing_handler_of_a_nested_dispatch_is_not_reported_as_the_outer_one(): void
    {
        $messengerBus = null;
        $messengerBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            CreateTaskCommand::class => [static function () use (&$messengerBus): void {
                self::assertInstanceOf(MessageBus::class, $messengerBus);
                $messengerBus->dispatch(new \stdClass());
            }],
        ]))]);

        $this->expectException(NoHandlerForMessageException::class);

        (new CommandBus($messengerBus))->dispatchSync(new CreateTaskCommand('1', 'x'));
    }

    public function test_dispatch_sync_ignores_deferral_stamps(): void
    {
        $messengerBus = null;
        $messengerBus = new MessageBus([
            new DispatchAfterCurrentBusMiddleware(),
            new HandleMessageMiddleware(new HandlersLocator([
                CreateTaskCommand::class => [static fn (CreateTaskCommand $command): string => 'created:'.$command->id],
                // Dispatched from inside a handler of the same bus, the stamp would defer handling
                // until this handler returns, leaving dispatchSync() without a result.
                \stdClass::class => [static function () use (&$messengerBus): mixed {
                    self::assertInstanceOf(MessageBus::class, $messengerBus);

                    return (new CommandBus($messengerBus))->dispatchSync(new CreateTaskCommand('1', 'x'), new DispatchAfterCurrentBusStamp());
                }],
            ])),
        ]);

        $envelope = $messengerBus->dispatch(new \stdClass());

        self::assertSame('created:1', $envelope->last(HandledStamp::class)?->getResult());
    }

    public function test_ask_reports_a_query_routed_to_a_transport(): void
    {
        $transport = new InMemoryTransport();
        $bus = new QueryBus(new MessageBus([
            new SendMessageMiddleware(new SendersLocator([FindTaskQuery::class => ['async']], new ServiceLocator(['async' => static fn () => $transport]))),
            new HandleMessageMiddleware(new HandlersLocator([]), true),
        ]), StampsDecider::withoutDecorators());

        $this->expectException(MessageSentToTransportException::class);

        $bus->ask(new FindTaskQuery('1'));
    }

    public function test_dispatch_sync_rejects_an_ambiguous_result(): void
    {
        // A catch-all handler for every command runs next to the command's own handler.
        // (Distinct handler classes: Messenger runs a handler name only once per message.)
        $bus = new CommandBus($this->bus([
            CreateTaskCommand::class => [new class {
                public function __invoke(CreateTaskCommand $command): string
                {
                    return 'task-1';
                }
            }],
            Command::class => [new class {
                public function __invoke(Command $command): null
                {
                    return null;
                }
            }],
        ]));

        try {
            $bus->dispatchSync(new CreateTaskCommand('1', 'x'));
            self::fail('Expected the ambiguous result to be reported.');
        } catch (MultipleHandlersException $exception) {
            self::assertSame(CreateTaskCommand::class, $exception->messageFqcn);
            self::assertSame(2, $exception->handlerCount);
        }
    }

    /**
     * @param array<class-string, list<callable>> $handlers
     */
    private function bus(array $handlers): MessageBus
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator($handlers))]);
    }
}
