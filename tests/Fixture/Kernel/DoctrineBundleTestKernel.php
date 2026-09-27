<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use SomeWork\CqrsBundle\DependencyInjection\Compiler\MessengerMiddlewareInjector;
use SomeWork\CqrsBundle\SomeWorkCqrsBundle;
use SomeWork\CqrsBundle\Tests\Fixture\Handler\TaskArchivedHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Outbox\TestDatabase;
use SomeWork\CqrsBundle\Tests\Fixture\Service\FlushCounter;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function dirname;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function md5;
use function str_starts_with;
use function strlen;
use function substr;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The outbox with the real DoctrineBundle (in-memory SQLite or the database of CQRS_TEST_DATABASE_URL,
 * ORM without mappings), whose middleware the outbox store must skip: its ids are what the bundle's
 * bypass depends on.
 *
 * - "event.async_bus" carries "doctrine_transaction" and "doctrine_open_transaction_logger" (and,
 *   from DoctrineBridge 8.2, DoctrineDbalTransactionMiddleware under its class name, as Symfony's
 *   documentation registers it): TaskArchivedEvent is stored through it, then relayed and handled.
 * - "query.bus" carries every middleware DoctrineBundle defines ("messenger.middleware.doctrine*"),
 *   added before Messenger builds the bus, so a middleware DoctrineBundle adds or renames shows up.
 *
 * The kernel records, in the "cqrs_test.doctrine_middleware" parameter, the class of each of those
 * middleware and whether the outbox wraps it on "query.bus", and the middleware of "event.async_bus"
 * in "cqrs_test.event_async_bus_middleware".
 */
final class DoctrineBundleTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const DBAL_TRANSACTION_MIDDLEWARE = 'Symfony\Bridge\Doctrine\Messenger\DoctrineDbalTransactionMiddleware';

    public const INVENTORY_BUS = 'query.bus';

    public const MIDDLEWARE_PREFIX = 'messenger.middleware.';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SomeWorkCqrsBundle();
    }

    protected function build(ContainerBuilder $container): void
    {
        // Before MessengerPass (priority 0, or -16 from Symfony 8.2), which builds the bus from this parameter.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $parameter = DoctrineBundleTestKernel::INVENTORY_BUS.'.middleware';
                $middleware = $container->getParameter($parameter);
                if (!is_array($middleware)) {
                    return;
                }
                foreach (DoctrineBundleTestKernel::doctrineMiddleware($container) as $id) {
                    $middleware[] = ['id' => substr($id, strlen(DoctrineBundleTestKernel::MIDDLEWARE_PREFIX)), 'arguments' => []];
                }
                $container->setParameter($parameter, $middleware);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);

        // After the bundle's OutboxStoreMiddlewarePass, which wraps the middleware of the CQRS buses.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $argument = MessengerMiddlewareInjector::findBusDefinition($container, DoctrineBundleTestKernel::INVENTORY_BUS)?->getArgument(0);
                $listed = $argument instanceof IteratorArgument ? array_values(array_map(static fn (mixed $middleware): string => (string) $middleware, $argument->getValues())) : [];

                $inventory = [];
                foreach (DoctrineBundleTestKernel::doctrineMiddleware($container) as $id) {
                    // Messenger lists a child definition of it per bus.
                    $onBus = DoctrineBundleTestKernel::INVENTORY_BUS.'.middleware.'.substr($id, strlen(DoctrineBundleTestKernel::MIDDLEWARE_PREFIX));
                    $wrapped = 'somework_cqrs.messenger.middleware.outbox_store.bypass.'.$onBus;
                    $inventory[$id] = [
                        'class' => DoctrineBundleTestKernel::classOf($container, $id),
                        'listed' => in_array($onBus, $listed, true) || in_array($wrapped, $listed, true),
                        'bypassed' => in_array($wrapped, $listed, true),
                    ];
                }
                $container->setParameter('cqrs_test.doctrine_middleware', $inventory);

                $argument = MessengerMiddlewareInjector::findBusDefinition($container, 'event.async_bus')?->getArgument(0);
                $container->setParameter('cqrs_test.event_async_bus_middleware', $argument instanceof IteratorArgument ? array_values(array_map(static fn (mixed $middleware): string => (string) $middleware, $argument->getValues())) : null);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, MessengerMiddlewareInjector::AFTER_MESSENGER_PASS - 10);
    }

    /**
     * The middleware services DoctrineBundle defines.
     *
     * @return list<string>
     */
    public static function doctrineMiddleware(ContainerBuilder $container): array
    {
        $ids = [];
        foreach (array_keys($container->getDefinitions()) as $id) {
            if (str_starts_with($id, self::MIDDLEWARE_PREFIX.'doctrine')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Keep the test output free of the default stderr logger.
        $container->services()->set('logger', NullLogger::class);

        $eventMiddleware = ['doctrine_transaction', 'doctrine_open_transaction_logger'];
        if (class_exists(self::DBAL_TRANSACTION_MIDDLEWARE)) {
            $eventMiddleware[] = self::DBAL_TRANSACTION_MIDDLEWARE;
        }

        $container->extension('framework', [
            'secret' => 'test-secret',
            'http_method_override' => false,
            'test' => true,
            'messenger' => [
                'default_bus' => 'command.bus',
                'buses' => [
                    'command.bus' => null,
                    'query.bus' => null,
                    'event.bus' => null,
                    'event.async_bus' => ['middleware' => $eventMiddleware],
                ],
                'transports' => [
                    'async' => 'in-memory://?serialize=true',
                ],
            ],
        ]);

        // The ORM without mappings: "doctrine_transaction" flushes its entity manager.
        $container->extension('doctrine', [
            'dbal' => ['url' => TestDatabase::url() ?? 'sqlite:///:memory:'],
            'orm' => [],
        ]);

        $container->extension('somework_cqrs', [
            'buses' => [
                'command' => 'command.bus',
                'query' => 'query.bus',
                'event' => 'event.bus',
                'event_async' => 'event.async_bus',
            ],
            'transports' => [
                'event_async' => ['default' => 'async'],
            ],
            'outbox' => ['enabled' => true, 'auto_setup' => false],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TaskRecorder::class)->public();
        $services->set(FlushCounter::class)
            ->tag('doctrine.event_listener', ['event' => Events::preFlush])
            ->public();
        $services->set(TaskArchivedHandler::class);
        if (class_exists(self::DBAL_TRANSACTION_MIDDLEWARE)) {
            $services->set(self::DBAL_TRANSACTION_MIDDLEWARE)->args([service('doctrine')]);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        // One container per database: the URL is compiled into it.
        return dirname(__DIR__, 3).'/var/cache/doctrine_bundle/'.md5(TestDatabase::url() ?? '').'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/log/doctrine_bundle';
    }

    /**
     * The class of a service, also when a child definition inherits it.
     */
    public static function classOf(ContainerBuilder $container, string $id): ?string
    {
        $definition = $container->getDefinition($id);
        while (null === $definition->getClass() && $definition instanceof ChildDefinition && $container->hasDefinition($definition->getParent())) {
            $definition = $container->getDefinition($definition->getParent());
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        return is_string($class) ? ltrim($class, '\\') : null;
    }
}
