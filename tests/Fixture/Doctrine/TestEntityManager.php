<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Tools\SchemaTool;

use function dirname;
use function method_exists;

use const PHP_VERSION_ID;

/**
 * Entity managers for the entities of tests/Fixture/Entity, on a connection of TestDatabase.
 */
final class TestEntityManager
{
    public static function create(Connection $connection, ?EventManager $eventManager = null): EntityManager
    {
        return new EntityManager($connection, self::configuration(), $eventManager ?? new EventManager());
    }

    /**
     * Creates the tables of the entities (TestDatabase::connect() drops them on a real database).
     */
    public static function createSchema(EntityManagerInterface $entityManager): void
    {
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    public static function configuration(): Configuration
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__).'/Entity'], true);
        if (PHP_VERSION_ID < 80400) {
            $configuration->setProxyDir(dirname(__DIR__, 3).'/var/cache/doctrine-proxies');
            $configuration->setProxyNamespace('SomeWork\CqrsBundle\Tests\Proxies');
            $configuration->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        } elseif (method_exists($configuration, 'enableNativeLazyObjects')) {
            // symfony/var-exporter 8 (PHP 8.4+) has no lazy ghosts left; ORM 4 always uses native lazy objects.
            $configuration->enableNativeLazyObjects(true);
        }

        return $configuration;
    }
}
