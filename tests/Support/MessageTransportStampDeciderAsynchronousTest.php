<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Support\MessageTransportResolver;
use SomeWork\CqrsBundle\Support\MessageTransportStampDecider;
use SomeWork\CqrsBundle\Support\MessageTransportStampFactory;
use SomeWork\CqrsBundle\Support\TransportResolverMap;
use SomeWork\CqrsBundle\Tests\Fixture\Message\AsyncTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\RetryAwareMessage;
use SomeWork\CqrsBundle\Tests\Fixture\Message\SendNotificationCommand;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function array_values;

/**
 * Transport precedence for messages carrying #[Asynchronous]: exact-class configuration, then the
 * attribute's transport, then parent/interface/default configuration, then "async" for a bare
 * attribute unless Messenger's routing routes the message.
 */
#[CoversClass(MessageTransportStampDecider::class)]
#[CoversClass(MessageTransportResolver::class)]
final class MessageTransportStampDeciderAsynchronousTest extends TestCase
{
    /**
     * @return iterable<string, array{object, array<string, list<string>>, list<string>, list<string>|null}>
     */
    public static function cases(): iterable
    {
        yield 'explicit transport without configuration' => [new SendNotificationCommand('1'), [], [], ['notifications']];
        yield 'explicit transport beats the type default' => [new SendNotificationCommand('1'), [MessageTransportResolver::DEFAULT_KEY => ['async']], [], ['notifications']];
        yield 'explicit transport beats an interface entry' => [new SendNotificationCommand('1'), [RetryAwareMessage::class => ['retrying']], [], ['notifications']];
        yield 'an exact class entry beats the attribute' => [new SendNotificationCommand('1'), [SendNotificationCommand::class => ['priority']], [], ['priority']];
        yield 'bare attribute uses the configured default' => [new AsyncTaskCommand('1'), [MessageTransportResolver::DEFAULT_KEY => ['jobs']], [], ['jobs']];
        yield 'bare attribute falls back to "async"' => [new AsyncTaskCommand('1'), [], [], ['async']];
        yield 'bare attribute leaves routed messages to Messenger' => [new AsyncTaskCommand('1'), [], [AsyncTaskCommand::class], null];
        yield 'bare attribute respects a wildcard route' => [new AsyncTaskCommand('1'), [], ['*'], null];
        yield 'bare attribute respects a namespace route' => [new AsyncTaskCommand('1'), [], ['SomeWork\\CqrsBundle\\Tests\\Fixture\\*'], null];
        yield 'bare attribute respects an interface route' => [new AsyncTaskCommand('1'), [], [\SomeWork\CqrsBundle\Contract\Command::class], null];
        yield 'a route for another namespace does not count' => [new AsyncTaskCommand('1'), [], ['App\\Message\\*'], ['async']];
    }

    /**
     * @param array<string, list<string>> $asyncConfiguration
     * @param list<string>                $routed
     * @param list<string>|null           $expected
     */
    #[DataProvider('cases')]
    public function test_transport_precedence(object $message, array $asyncConfiguration, array $routed, ?array $expected): void
    {
        $stamps = $this->decider($asyncConfiguration, $routed)->decide($message, DispatchMode::ASYNC, []);

        self::assertSame($expected, self::transports($stamps));
    }

    public function test_the_attribute_is_ignored_for_synchronous_dispatch(): void
    {
        self::assertNull(self::transports($this->decider([], [])->decide(new SendNotificationCommand('1'), DispatchMode::SYNC, [])));
    }

    public function test_a_stamp_passed_by_the_caller_wins(): void
    {
        $callerStamp = new TransportNamesStamp(['caller']);

        self::assertSame([$callerStamp], $this->decider([SendNotificationCommand::class => ['priority']], [])->decide(new SendNotificationCommand('1'), DispatchMode::ASYNC, [$callerStamp]));
    }

    /**
     * @param array<string, list<string>> $asyncConfiguration
     * @param list<string>                $routed
     */
    private function decider(array $asyncConfiguration, array $routed): MessageTransportStampDecider
    {
        $factories = [];
        foreach ($asyncConfiguration as $key => $transports) {
            $factories[$key] = static fn (): array => $transports;
        }

        return new MessageTransportStampDecider(
            new MessageTransportStampFactory(),
            new TransportResolverMap(async: new MessageTransportResolver(new ServiceLocator($factories))),
            new TransportResolverMap(),
            new TransportResolverMap(),
            routedMessageTypes: $routed,
        );
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return list<string>|null
     */
    private static function transports(array $stamps): ?array
    {
        foreach (array_values($stamps) as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                return array_values($stamp->getTransportNames());
            }
        }

        return null;
    }
}
