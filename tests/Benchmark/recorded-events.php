<?php

declare(strict_types=1);

/*
 * The cost of the listener of "somework_cqrs.doctrine_events" (two scans of the identity map per
 * flush): a flush of 10 000 managed entities of which 1% recorded an event (and changed), with and
 * without the listener.
 *
 *   php tests/Benchmark/recorded-events.php [entities] [recording percentage] [runs]
 *
 * In-memory SQLite, or the database of CQRS_TEST_DATABASE_URL (its test tables are dropped).
 */

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use SomeWork\CqrsBundle\Bus\EventBus;
use SomeWork\CqrsBundle\Doctrine\RecordedEventsListener;
use SomeWork\CqrsBundle\Messenger\OutboxPrepareMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use SomeWork\CqrsBundle\Outbox\DbalOutboxStorage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use SomeWork\CqrsBundle\Testing\FakeEventBus;
use SomeWork\CqrsBundle\Tests\Fixture\Doctrine\TestEntityManager;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Article;
use SomeWork\CqrsBundle\Tests\Fixture\Entity\Tag;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$entities = (int) ($argv[1] ?? 10000);
$percentage = (float) ($argv[2] ?? 1.0);
$runs = (int) ($argv[3] ?? 5);
$changed = max(1, (int) round($entities * $percentage / 100));

/**
 * One flush of $changed changes among $entities managed entities, in a transaction.
 *
 * @param 'none'|'fake'|'outbox' $listener
 * @param bool                   $recording Article records events, Tag does not
 *
 * @return array{float, float} The flush time and the time of the listener's hooks, in milliseconds
 */
$flush = static function (string $listener, bool $recording) use ($entities, $changed): array {
    $connection = TestDatabase::connect();
    $events = new EventManager();
    $entityManager = TestEntityManager::create($connection, $events);
    TestEntityManager::createSchema($entityManager);
    $storage = new DbalOutboxStorage($connection, autoSetup: false);
    $storage->setup();

    $table = $recording ? 'cqrs_test_article' : 'cqrs_test_tag';
    $connection->transactional(static function (Connection $connection) use ($table, $entities): void {
        for ($i = 0; $i < $entities; ++$i) {
            $connection->insert($table, ['id' => 'e'.$i, 'title' => 'Title '.$i]);
        }
    });

    $hooks = 0.0;
    if ('none' !== $listener) {
        $bus = 'fake' === $listener ? new FakeEventBus() : (static function () use ($storage): EventBus {
            $writer = new OutboxWriter($storage, new PhpSerializer(), transaction: $storage, requireTransaction: true);

            return new EventBus(new MessageBus([new OutboxPrepareMiddleware(), new OutboxStoreMiddleware($writer)]), outbox: $writer);
        })();
        $recorded = new RecordedEventsListener(static fn (): RecordedEventsPublisher => new RecordedEventsPublisher($bus, $connection), $connection);
        // Times the hooks of the bundle's listener alone.
        $events->addEventListener([Events::onFlush, Events::postFlush, Events::onClear], new class($recorded, $hooks) {
            public function __construct(private readonly RecordedEventsListener $listener, private float &$time)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                $start = hrtime(true);
                $this->listener->onFlush($args);
                $this->time += (hrtime(true) - $start) / 1e6;
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                $start = hrtime(true);
                $this->listener->postFlush($args);
                $this->time += (hrtime(true) - $start) / 1e6;
            }

            public function onClear(OnClearEventArgs $args): void
            {
                $this->listener->onClear($args);
            }
        });
    }

    $managed = $recording ? $entityManager->getRepository(Article::class)->findAll() : $entityManager->getRepository(Tag::class)->findAll();
    if (count($managed) !== $entities) {
        throw new RuntimeException('The entities were not loaded.');
    }
    foreach (array_slice($managed, 0, $changed) as $entity) {
        $entity->rename('Renamed');
    }

    $start = hrtime(true);
    $entityManager->wrapInTransaction(static function (): void {
    });
    $time = (hrtime(true) - $start) / 1e6;

    $stored = (int) $connection->fetchOne('SELECT COUNT(*) FROM somework_cqrs_outbox');
    $expected = 'outbox' === $listener && $recording ? $changed : 0;
    if ($stored !== $expected) {
        throw new RuntimeException(sprintf('%d rows stored, %d expected.', $stored, $expected));
    }
    $entityManager->close();
    $connection->close();

    return [$time, $hooks];
};

$median = static function (array $values): float {
    sort($values);

    return $values[intdiv(count($values), 2)];
};

$platform = TestDatabase::connect(keepTables: true)->getDatabasePlatform()::class;
printf("%d managed entities, %d changed (%.1f%%), median of %d runs, PHP %s, %s\n\n", $entities, $changed, $percentage, $runs, \PHP_VERSION, substr($platform, (int) strrpos($platform, '\\') + 1));
printf("| %-58s | %10s | %14s |\n", 'Flush (in a transaction)', 'flush (ms)', 'listener (ms)');
printf("|%s|%s|%s|\n", str_repeat('-', 60), str_repeat('-', 12), str_repeat('-', 16));
foreach ([
    ['none', true, 'Recording entities, no listener (baseline)'],
    ['fake', true, 'Recording entities, listener, FakeEventBus (scans only)'],
    ['outbox', true, 'Recording entities, listener, outbox rows stored'],
    ['none', false, 'Entities that record nothing, no listener'],
    ['fake', false, 'Entities that record nothing, listener'],
] as [$listener, $recording, $label]) {
    $flushes = $hooks = [];
    for ($run = 0; $run < $runs; ++$run) {
        [$flushes[], $hooks[]] = $flush($listener, $recording);
    }
    printf("| %-58s | %10.1f | %14s |\n", $label, $median($flushes), 'none' === $listener ? '-' : sprintf('%.1f', $median($hooks)));
}
