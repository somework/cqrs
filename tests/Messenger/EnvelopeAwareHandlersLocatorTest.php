<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Messenger;

use Closure;
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
        /** @var \WeakMap $cache */
        $cache = $cacheProperty->getValue($locator);
        $cachedEntries = array_values(iterator_to_array($cache, false));
        self::assertCount(1, $cachedEntries);
        $firstCacheEntry = $cachedEntries[0];
        self::assertIsArray($firstCacheEntry);
        self::assertArrayHasKey('reflection', $firstCacheEntry);
        $firstReflectionId = spl_object_id($firstCacheEntry['reflection']);

        $secondHandler = iterator_to_array($locator->getHandlers($secondEnvelope), false)[0];
        $secondHandler->getHandler()(new \stdClass());

        $cachedEntries = array_values(iterator_to_array($cache, false));
        self::assertCount(1, $cachedEntries);
        $secondCacheEntry = $cachedEntries[0];
        self::assertIsArray($secondCacheEntry);
        self::assertSame($firstReflectionId, spl_object_id($secondCacheEntry['reflection']));

        self::assertSame(2, $handler->setEnvelopeCalls);
        self::assertSame($firstEnvelope, $handler->envelopes[0]);
        self::assertSame($secondEnvelope, $handler->envelopes[1]);
        self::assertCount(2, $handler->handledMessages);
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
