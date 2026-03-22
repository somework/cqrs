<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Messenger\EnvelopeAwareHandlersLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

final class EnvelopeAwareHandlersLocatorTest extends TestCase
{
    public function test_envelope_aware_handlers_are_cached(): void
    {
        $handler = new SpyEnvelopeAwareHandler();
        $callable = Closure::fromCallable($handler);
        $descriptor = new HandlerDescriptor($callable);

        $decoratedLocator = new class($descriptor) implements HandlersLocatorInterface {
            public function __construct(private HandlerDescriptor $descriptor)
            {
            }

            public function getHandlers(Envelope $envelope): iterable
            {
                yield $this->descriptor;
            }
        };

        $locator = new EnvelopeAwareHandlersLocator($decoratedLocator);

        $firstEnvelope = new Envelope(new \stdClass());
        $secondEnvelope = new Envelope(new \stdClass());

        $firstHandler = iterator_to_array($locator->getHandlers($firstEnvelope), false)[0];
        $firstHandler->getHandler()(new \stdClass());

        $cacheProperty = (new \ReflectionClass($locator))->getProperty('handlerCache');
        $cacheProperty->setAccessible(true);
        /** @var \WeakMap<object, array<string, mixed>> $cache */
        $cache = $cacheProperty->getValue($locator);
        $cachedEntries = iterator_to_array($cache, false);
        self::assertCount(1, $cachedEntries);
        $firstCacheEntry = $cachedEntries[0];
        self::assertArrayHasKey('reflection', $firstCacheEntry);
        $firstReflectionId = spl_object_id($firstCacheEntry['reflection']);

        $secondHandler = iterator_to_array($locator->getHandlers($secondEnvelope), false)[0];
        $secondHandler->getHandler()(new \stdClass());

        $cachedEntries = iterator_to_array($cache, false);
        self::assertCount(1, $cachedEntries);
        $secondCacheEntry = $cachedEntries[0];
        self::assertSame($firstReflectionId, spl_object_id($secondCacheEntry['reflection']));

        self::assertSame(2, $handler->setEnvelopeCalls);
        self::assertSame($firstEnvelope, $handler->envelopes[0]);
        self::assertSame($secondEnvelope, $handler->envelopes[1]);
        self::assertCount(2, $handler->handledMessages);
    }

    #[Test]
    public function test_envelope_freshness_on_repeated_get_handlers_calls(): void
    {
        $handler = new SpyEnvelopeAwareHandler();
        $callable = Closure::fromCallable($handler);
        $descriptor = new HandlerDescriptor($callable);

        $decoratedLocator = new class($descriptor) implements HandlersLocatorInterface {
            public function __construct(private readonly HandlerDescriptor $descriptor)
            {
            }

            public function getHandlers(Envelope $envelope): iterable
            {
                yield $this->descriptor;
            }
        };

        $locator = new EnvelopeAwareHandlersLocator($decoratedLocator);

        $envelope1 = new Envelope(new \stdClass());
        $envelope2 = new Envelope(new \stdClass());
        $envelope3 = new Envelope(new \stdClass());

        $result1 = iterator_to_array($locator->getHandlers($envelope1), false);
        $result1[0]->getHandler()(new \stdClass());

        $result2 = iterator_to_array($locator->getHandlers($envelope2), false);
        $result2[0]->getHandler()(new \stdClass());

        $result3 = iterator_to_array($locator->getHandlers($envelope3), false);
        $result3[0]->getHandler()(new \stdClass());

        self::assertSame(3, $handler->setEnvelopeCalls);
        self::assertSame($envelope1, $handler->envelopes[0]);
        self::assertSame($envelope2, $handler->envelopes[1]);
        self::assertSame($envelope3, $handler->envelopes[2]);
        self::assertCount(3, $handler->handledMessages);
    }

    #[Test]
    public function test_handler_options_are_preserved_after_wrapping(): void
    {
        $handler = new SpyEnvelopeAwareHandler();
        $callable = Closure::fromCallable($handler);
        $descriptor = new HandlerDescriptor($callable, ['from_transport' => 'async', 'priority' => 10]);

        $decoratedLocator = new class($descriptor) implements HandlersLocatorInterface {
            public function __construct(private readonly HandlerDescriptor $descriptor)
            {
            }

            public function getHandlers(Envelope $envelope): iterable
            {
                yield $this->descriptor;
            }
        };

        $locator = new EnvelopeAwareHandlersLocator($decoratedLocator);

        $result = iterator_to_array($locator->getHandlers(new Envelope(new \stdClass())), false);
        self::assertCount(1, $result);

        $wrappedDescriptor = $result[0];
        self::assertSame('async', $wrappedDescriptor->getOption('from_transport'));
        self::assertSame(10, $wrappedDescriptor->getOption('priority'));

        $wrappedDescriptor->getHandler()(new \stdClass());
        self::assertCount(1, $handler->handledMessages);
    }
}

final class SpyEnvelopeAwareHandler implements EnvelopeAware
{
    public int $setEnvelopeCalls = 0;

    /** @var Envelope[] */
    public array $envelopes = [];

    /** @var list<object> */
    public array $handledMessages = [];

    public function setEnvelope(Envelope $envelope): void
    {
        ++$this->setEnvelopeCalls;
        $this->envelopes[] = $envelope;
    }

    public function __invoke(object $message): void
    {
        $this->handledMessages[] = $message;
    }
}
