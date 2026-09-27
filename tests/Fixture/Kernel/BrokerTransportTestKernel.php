<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\CreateTaskHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskArchivedHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function getenv;
use function is_string;
use function md5;

/**
 * The async setup of AsyncTransportTestKernel and the outbox of OutboxTestKernel on a real
 * message broker: the "async" transport uses the DSN of CQRS_TEST_TRANSPORT_DSN (RabbitMQ, Redis,
 * Amazon SQS, ...), whose Messenger bridge must be installed.
 */
final class BrokerTransportTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const DSN_VARIABLE = 'CQRS_TEST_TRANSPORT_DSN';

    /**
     * FrameworkBundle only registers the transport factory of a bridge the application requires
     * outside require-dev (ContainerBuilder::willBeAvailable()); CI installs them as dev packages.
     */
    private const TRANSPORT_FACTORIES = [
        'symfony/amqp-messenger' => 'Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory',
        'symfony/amazon-sqs-messenger' => 'Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory',
        'symfony/redis-messenger' => 'Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory',
    ];

    public static function dsn(): ?string
    {
        $dsn = getenv(self::DSN_VARIABLE);

        return is_string($dsn) && '' !== $dsn ? $dsn : null;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SomeWorkCqrsBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Keep the test output free of the default stderr logger.
        $container->services()->set('logger', NullLogger::class);

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'messenger' => [
                'default_bus' => 'command.bus',
                'buses' => [
                    'command.bus' => null,
                    'command.async_bus' => null,
                    'event.bus' => null,
                    'event.async_bus' => null,
                ],
                'transports' => [
                    'async' => ['dsn' => self::dsn() ?? 'in-memory://', 'retry_strategy' => ['max_retries' => 0]],
                ],
            ],
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'command_async' => 'command.async_bus',
                'event' => 'event.bus',
                'event_async' => 'event.async_bus',
            ],
            'transports' => [
                'command_async' => ['default' => 'async'],
                'event_async' => ['default' => 'async'],
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // In-memory SQLite, or the database of CQRS_TEST_DATABASE_URL.
        $services->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([TestDatabase::class, 'connect'])
            ->public();
        $services->set(TaskRecorder::class)->public();
        $services->set(CreateTaskHandler::class);
        $services->set(TaskArchivedHandler::class);
        foreach (self::TRANSPORT_FACTORIES as $package => $factory) {
            if (InstalledVersions::isInstalled($package)) {
                $services->set('cqrs_test.transport_factory.'.$factory, $factory)
                    ->autowire(false)
                    ->autoconfigure(false)
                    ->tag('messenger.transport_factory');
            }
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /**
     * One container per broker: the DSN is compiled into it.
     */
    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/cache/broker_transport/'.md5(self::dsn() ?? '').'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/broker_transport';
    }
}
