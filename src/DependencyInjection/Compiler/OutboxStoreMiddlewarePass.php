<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\DependencyInjection\Compiler;

use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function is_string;
use function sort;
use function sprintf;

/**
 * With the outbox enabled, inserts OutboxStoreMiddleware right before Messenger's "send_message"
 * on the CQRS buses (DispatchMode::OUTBOX stores through them), and gives the outbox writer the
 * Messenger transport names, so a row for an unknown transport is refused before it is stored.
 *
 * @internal
 */
final class OutboxStoreMiddlewarePass implements CompilerPassInterface
{
    public const MIDDLEWARE_ID = 'somework_cqrs.messenger.middleware.outbox_store';

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

        foreach (CqrsBusIds::resolve($container) as $busId) {
            if (!MessengerMiddlewareInjector::injectBefore($container, $busId, self::MIDDLEWARE_ID)) {
                throw new LogicException(sprintf('The outbox needs its middleware on the Messenger bus "%s" (a CQRS bus), but the bundle cannot find the middleware list of that bus.', $busId));
            }
        }
    }
}
