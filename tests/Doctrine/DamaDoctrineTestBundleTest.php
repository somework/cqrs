<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Doctrine;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\Middleware;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Tests\Fixture\Doctrine\TestEntityManager;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;

/**
 * DAMA\DoctrineTestBundle wraps each test in a transaction below DBAL (its static driver): the
 * connection does not see it, so a plain flush of recording entities is still refused in tests.
 */
#[Group('doctrine-bundle')]
#[CoversClass(RecordedEventsListener::class)]
final class DamaDoctrineTestBundleTest extends TestCase
{
    protected function setUp(): void
    {
        StaticDriver::setKeepStaticConnections(true);
    }

    protected function tearDown(): void
    {
        StaticDriver::rollBack();
        StaticDriver::setKeepStaticConnections(false);
        (new \ReflectionProperty(StaticDriver::class, 'connections'))->setValue(null, []);
    }

    public function test_the_transaction_of_the_test_does_not_count_as_the_transaction_of_the_flush(): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'dama.connection_key' => 'default'], $configuration);
        $bus = new FakeEventBus();
        $events = new EventManager();
        $events->addEventListener([Events::onFlush, Events::postFlush, Events::onClear], new RecordedEventsListener(static fn (): RecordedEventsPublisher => new RecordedEventsPublisher($bus, $connection), $connection));
        $entityManager = TestEntityManager::create($connection, $events);
        TestEntityManager::createSchema($entityManager);
        $entityManager->persist(new Article('a1', 'Draft'));

        try {
            $entityManager->flush();
            self::fail('The flush should have been refused.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(OutboxRequiresTransactionException::class, $exception);
        }

        $entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager): void {
        });
        self::assertCount(1, $bus->getDispatched());
    }
}
