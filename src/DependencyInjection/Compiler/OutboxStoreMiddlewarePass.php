<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Messenger\OutboxBypassMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxPrepareMiddleware;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function in_array;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * With the outbox enabled, inserts the outbox middleware on the CQRS buses (DispatchMode::OUTBOX
 * stores through them): OutboxPrepareMiddleware right after Messenger's
 * "add_default_stamps_middleware" (or first), and OutboxStoreMiddleware after the application's
 * middleware, right before "send_message". Doctrine's transaction middleware is wrapped so that a
 * message stored in the outbox skips it. It also gives the outbox writer the Messenger transport
 * names, so a row for an unknown transport is refused before it is stored.
 *
 * @internal
 */
final class OutboxStoreMiddlewarePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.outbox_store';

    public const PREPARE_MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.outbox_prepare';

    /**
     * Middleware that belongs to handling: at store time it would flush the caller's entity manager,
     * or report its open transaction. Messenger uses these services, or child definitions of them
     * ("<bus>.middleware.<name>", with a hash suffix when a bus lists it more than once).
     */
    private const BYPASSED = ['messenger.middleware.doctrine_transaction', 'messenger.middleware.doctrine_open_transaction_logger'];

    /** The same, by id, for definitions whose parent is not visible (e.g. already resolved). */
    private const BYPASSED_IDS = '/(?:^|\.)(?:doctrine_transaction|doctrine_open_transaction_logger)(?:\.[A-Za-z0-9_.]+)?$/';

    private const WRITER_ID = 'somework_cqrs.outbox.writer';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::WRITER_ID)) {
            return;
        }

        $transports = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $tags) {
            foreach ($tags as $tag) {
                if (is_string($tag['alias'] ?? null) && '' !== $tag['alias']) {
                    $transports[$tag['alias']] = true;
                }
            }
        }
        $transports = array_keys($transports);
        sort($transports);
        // Without Messenger transports (a kernel that defines them another way), nothing is refused.
        $container->getDefinition(self::WRITER_ID)->setArgument('$transportNames', [] === $transports ? null : $transports);

        $container->setDefinition(self::MIDDLEWARE_ID, (new Definition(OutboxStoreMiddleware::class))
            ->setArguments([new Reference(self::WRITER_ID)])
            ->setPublic(false));
        $container->setDefinition(self::PREPARE_MIDDLEWARE_ID, (new Definition(OutboxPrepareMiddleware::class))->setPublic(false));

        foreach (CqrsBusIds::resolve($container) as $busId) {
            if (!MessengerMiddlewareInjector::injectBefore($container, $busId, self::MIDDLEWARE_ID)
                || !MessengerMiddlewareInjector::inject($container, $busId, self::PREPARE_MIDDLEWARE_ID, 'add_default_stamps_middleware')) {
                throw new LogicException(sprintf('The outbox needs its middleware on the Messenger bus "%s" (a CQRS bus), but the bundle cannot find the middleware list of that bus.', $busId));
            }
            $this->bypassHandlingMiddleware($container, $busId);
        }
    }

    private function bypassHandlingMiddleware(ContainerBuilder $container, string $busId): void
    {
        $definition = MessengerMiddlewareInjector::findBusDefinition($container, $busId);
        $argument = $definition?->getArgument(0);
        if (null === $definition || !$argument instanceof IteratorArgument) {
            return;
        }

        $middlewares = [];
        foreach ($argument->getValues() as $middleware) {
            $id = (string) $middleware;
            if ($middleware instanceof Reference && !str_starts_with($id, self::MIDDLEWARE_ID) && self::belongsToHandling($container, $id)) {
                $wrapperId = self::MIDDLEWARE_ID.'.bypass.'.$id;
                if (!$container->hasDefinition($wrapperId)) {
                    $container->setDefinition($wrapperId, (new Definition(OutboxBypassMiddleware::class))
                        ->setArguments([new Reference($id)])
                        ->setPublic(false));
                }
                $middleware = new Reference($wrapperId);
            }
            $middlewares[] = $middleware;
        }

        $definition->replaceArgument(0, new IteratorArgument($middlewares));
    }

    private static function belongsToHandling(ContainerBuilder $container, string $id): bool
    {
        if (in_array($id, self::BYPASSED, true)) {
            return true;
        }

        $definition = $container->hasDefinition($id) ? $container->getDefinition($id) : null;
        if ($definition instanceof ChildDefinition) {
            return in_array($definition->getParent(), self::BYPASSED, true);
        }

        return 1 === preg_match(self::BYPASSED_IDS, $id);
    }
}
