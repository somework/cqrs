<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
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
