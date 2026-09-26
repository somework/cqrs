<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use SomeWork\CqrsBundle\Stamp\MessageMetadataStamp;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\GenerateReportCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;

use function array_map;
use function iterator_to_array;

#[CoversClass(EnvelopeAwareHandlersLocator::class)]
final class EnvelopeAwareHandlersLocatorTest extends TestCase
{
    public function test_yields_the_original_descriptors(): void
    {
        $descriptor = new HandlerDescriptor(new SpyEnvelopeAwareHandler(), ['from_transport' => 'async', 'priority' => 10]);
        $locator = new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$descriptor]]));

        $descriptors = iterator_to_array($locator->getHandlers(new Envelope(new \stdClass())), false);

        self::assertSame([$descriptor], $descriptors);
        self::assertSame(SpyEnvelopeAwareHandler::class.'::__invoke', $descriptors[0]->getName());
    }

    public function test_sets_the_current_envelope_before_each_handler_is_yielded(): void
    {
        $handler = new SpyEnvelopeAwareHandler();
        $locator = new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$handler]]));

        $first = new Envelope(new \stdClass());
        $second = new Envelope(new \stdClass());

        iterator_to_array($locator->getHandlers($first));
        iterator_to_array($locator->getHandlers($second));

        self::assertSame([$first, $second], $handler->envelopes);
    }

    public function test_envelope_is_set_lazily_when_the_handler_is_reached(): void
    {
        $firstHandler = new SpyEnvelopeAwareHandler();
        $secondHandler = new SpyEnvelopeAwareHandler();
        $locator = new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$firstHandler, $secondHandler]]));

        $handlers = $locator->getHandlers(new Envelope(new \stdClass()));
        self::assertInstanceOf(\Generator::class, $handlers);

        $handlers->current();

        self::assertCount(1, $firstHandler->envelopes);
        self::assertSame([], $secondHandler->envelopes);
    }

    public function test_ignores_handlers_that_are_not_envelope_aware(): void
    {
        $handler = static fn (\stdClass $message): string => 'plain';
        $locator = new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$handler]]));

        $descriptors = iterator_to_array($locator->getHandlers(new Envelope(new \stdClass())), false);

        self::assertCount(1, $descriptors);
        self::assertSame('plain', ($descriptors[0]->getHandler())(new \stdClass()));
    }

    public function test_every_envelope_aware_handler_of_a_message_runs_on_the_bus(): void
    {
        $first = new SpyEnvelopeAwareHandler();
        $second = new OtherSpyEnvelopeAwareHandler();
        $bus = new MessageBus([
            new HandleMessageMiddleware(new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$first, $second]]))),
        ]);

        $envelope = $bus->dispatch(new \stdClass());

        self::assertCount(1, $first->handledMessages);
        self::assertCount(1, $second->handledMessages);
        self::assertSame(
            [SpyEnvelopeAwareHandler::class.'::__invoke', OtherSpyEnvelopeAwareHandler::class.'::__invoke'],
            array_map(static fn (HandledStamp $stamp): string => $stamp->getHandlerName(), $envelope->all(HandledStamp::class)),
        );
    }

    public function test_a_plain_handler_followed_by_an_envelope_aware_handler_both_run(): void
    {
        $plain = new PlainSpyHandler();
        $aware = new SpyEnvelopeAwareHandler();
        $plainDescriptor = new HandlerDescriptor($plain);
        $awareDescriptor = new HandlerDescriptor($aware);
        $locator = new EnvelopeAwareHandlersLocator(new HandlersLocator([\stdClass::class => [$plainDescriptor, $awareDescriptor]]));
        $bus = new MessageBus([new HandleMessageMiddleware($locator)]);
        $message = new \stdClass();

        $envelope = $bus->dispatch($message, [new MessageMetadataStamp('correlation')]);

        self::assertSame([$message], $plain->handledMessages);
        self::assertSame([], $plain->envelopes, 'A handler that is not EnvelopeAware must not receive the envelope.');
        self::assertSame([$message], $aware->handledMessages);
        self::assertCount(1, $aware->envelopes);
        self::assertSame($message, $aware->envelopes[0]->getMessage());
        self::assertSame('correlation', $aware->envelopes[0]->last(MessageMetadataStamp::class)?->getCorrelationId());
        self::assertSame(
            [PlainSpyHandler::class.'::__invoke', SpyEnvelopeAwareHandler::class.'::__invoke'],
            array_map(static fn (HandledStamp $stamp): string => $stamp->getHandlerName(), $envelope->all(HandledStamp::class)),
        );
        self::assertSame(
            [$plainDescriptor, $awareDescriptor],
            iterator_to_array($locator->getHandlers(new Envelope($message)), false),
        );
    }

    public function test_a_nested_dispatch_to_the_same_handler_restores_the_outer_envelope(): void
    {
        $handler = new class implements EnvelopeAware {
            use EnvelopeAwareTrait;

            /** @var list<string> */
            public array $seen = [];

            public ?\Closure $whileHandlingCreate = null;

            public function __invoke(object $message): void
            {
                $this->seen[] = $message::class.'@'.$this->correlationId();

                if ($message instanceof CreateTaskCommand && null !== $this->whileHandlingCreate) {
                    ($this->whileHandlingCreate)();
                    $this->seen[] = $message::class.'@'.$this->correlationId();
                }
            }

            private function correlationId(): string
            {
                return $this->getEnvelope()->last(MessageMetadataStamp::class)?->getCorrelationId() ?? '';
            }
        };
        $descriptor = new HandlerDescriptor($handler);
        $bus = new MessageBus([new HandleMessageMiddleware(new EnvelopeAwareHandlersLocator(new HandlersLocator([
            CreateTaskCommand::class => [$descriptor],
            GenerateReportCommand::class => [$descriptor],
        ])))]);
        $handler->whileHandlingCreate = static fn () => $bus->dispatch(new GenerateReportCommand('r-1'), [new MessageMetadataStamp('inner')]);

        $bus->dispatch(new CreateTaskCommand('1', 'x'), [new MessageMetadataStamp('outer')]);

        self::assertSame([
            CreateTaskCommand::class.'@outer',
            GenerateReportCommand::class.'@inner',
            CreateTaskCommand::class.'@outer',
        ], $handler->seen);
    }
}

class SpyEnvelopeAwareHandler implements EnvelopeAware
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    /** @var list<object> */
    public array $handledMessages = [];

    public function setEnvelope(Envelope $envelope): void
    {
        $this->envelopes[] = $envelope;
    }

    public function __invoke(object $message): void
    {
        $this->handledMessages[] = $message;
    }
}

final class OtherSpyEnvelopeAwareHandler extends SpyEnvelopeAwareHandler
{
}

/**
 * Has a setEnvelope() method but does not implement EnvelopeAware.
 */
final class PlainSpyHandler
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    /** @var list<object> */
    public array $handledMessages = [];

    public function setEnvelope(Envelope $envelope): void
    {
        $this->envelopes[] = $envelope;
    }

    public function __invoke(object $message): void
    {
        $this->handledMessages[] = $message;
    }
}
