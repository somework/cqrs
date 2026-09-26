<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use SomeWork\CqrsBundle\Exception\DeferredDispatchFailedException;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function count;

/**
 * Dispatches commands through configured Messenger buses.
 *
 * @api
 */
final class CommandBus extends AbstractMessengerBus implements CommandBusInterface
{
    protected const BUS_NAME = 'command';

    /**
     * @internal Get the bus from the container (autowire the interface); the constructor
     *           arguments are internal services and change without notice
     */
    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($syncBus, $asyncBus, $dispatchModeDecider, $stampsDecider, $logger);
    }

    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($command, $mode, ...$stamps);
    }

    /**
     * Handles the command synchronously and returns the handler result.
     *
     * A DispatchAfterCurrentBusStamp is ignored because the result is needed immediately.
     * When the only handler throws, its exception is rethrown as is (not wrapped in
     * Messenger's HandlerFailedException).
     *
     * @throws NoHandlerException              when no handler handled the command
     * @throws MessageSentToTransportException when the routing sent the command to a transport
     * @throws DeferredDispatchFailedException when the handler succeeded but a message it deferred (DispatchAfterCurrentBusStamp) failed afterwards
     * @throws DuplicateMessageException       when deduplication dropped the command
     * @throws MultipleHandlersException       when more than one handler handled the command (the result would be ambiguous)
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        try {
            $envelope = $this->dispatchMessageSync($command, ...SynchronousResult::withoutDeferral($stamps));
        } catch (HandlerFailedException $exception) {
            throw SynchronousResult::unwrap($exception);
        } catch (DelayedMessageHandlingException $exception) {
            throw DeferredDispatchFailedException::fromDelayedHandling($command::class, self::BUS_NAME, $exception);
        } catch (NoHandlerForMessageException $exception) {
            throw new NoHandlerException($command::class, self::BUS_NAME, $exception);
        }

        $handledStamps = SynchronousResult::handledStamps($envelope, self::BUS_NAME);

        // Messenger also runs handlers registered for parent classes and interfaces of the command.
        if (count($handledStamps) > 1) {
            throw new MultipleHandlersException($command::class, self::BUS_NAME, count($handledStamps));
        }

        return $handledStamps[0]->getResult();
    }

    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageAsync($command, ...$stamps);
    }
}
