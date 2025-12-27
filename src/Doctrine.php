<?php

namespace Kipchak\Driver\Doctrine;

use Kipchak\Core\Interfaces;
use Psr\Container\ContainerInterface;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Logger\Logger;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Kipchak\Driver\Memcached\Memcached as MemcachedDriver;
use Kipchak\Driver\Filecache\Filecache as FilecacheDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\Connection;

class Doctrine implements Interfaces\Driver
{
    protected static ContainerInterface $container;

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $configFileName = $configFileName ?? 'kipchak.doctrine';
        // Create all Doctrine DBAL Connections
        if (!empty(Config::get($configFileName)['dbal'])  && Config::get($configFileName)['dbal']['enabled']) {
            $dbalConfig = Config::get($configFileName)['dbal'];
            $dbalConnections = $dbalConfig['connections'];
            foreach ($dbalConnections as $dbalConnectionName => $dbalConnection) {
                self::$container->set('drivers.database.doctrine.dbal.' . $dbalConnectionName, function (ContainerInterface $c) use ($dbalConnectionName, $dbalConnection): Connection {
                    return DriverManager::getConnection(
                        [
                            'dbname'    =>  $dbalConnection['dbname'],
                            'user'      =>  $dbalConnection['user'],
                            'password'  =>  $dbalConnection['password'],
                            'host'      =>  $dbalConnection['host'],
                            'driver'    =>  $dbalConnection['driver'],
                            'port'      =>  $dbalConnection['port'],
                            'charset'   =>  $dbalConnection['charset']
                        ]
                    );
                });
            }
        }

// Create all Doctrine Entity Managers
        if (isset(Config::get($configFileName)['orm'])  && Config::get($configFileName)['orm']['enabled']) {
            $ormConfig = Config::get($configFileName)['orm'];
            $entityManagers = $ormConfig['entity_managers'];
            $log = Logger::get();
            foreach ($entityManagers as $emName => $emConnection) {
                self::$container->set('drivers.database.doctrine.entitymanager.' . $emName, function (ContainerInterface $c) use ($emName, $emConnection, $log, $configFileName): EntityManager {
                    if (!is_dir($emConnection['cache_config']['file']['dir'])) {
                        $log->debug('Doctrine ORM: Creating Cache Directory '. $emConnection['cache_config']['file']['dir'] . '.');
                        mkdir ($emConnection['cache_config']['file']['dir'], 0777, true);
                    }

                    if ($emConnection['dev_mode']) {
                        $log->debug('Doctrine ORM: Dev mode detected. Using Array Adapter for caching.');
                        $cache = new ArrayAdapter();
                    } elseif (!$emConnection['dev_mode'] && $emConnection['cache']['enabled']) {
                        $cacheStore = $emConnection['cache']['store'];
                        $log->debug('Doctrine ORM: Production mode detected. Cache store is set to ' . $cacheStore . '.');
                        if ($cacheStore == 'file') {
                            FilecacheDriver::doctrineSet($emName, $emConnection['cache_config']['file']['dir'], 3600);
                            $cache = FilecacheDriver::doctrineGet($emName);
                        } elseif ($cacheStore == 'memcached') {
                            $log->debug('Doctrine ORM: Memcached pool name is set to '. $cacheStore . '.');
                            $connectionPool = $emConnection['cache_config']['memcached']['pool'];
                            $cache = MemcachedDriver::get($connectionPool);
                            // $cache = $c->get('drivers.cache.memcached.' . $connectionPool);
                        } else {
                            // Default to file store anyway. The file store in the default implementation is not namespaced.
                            FilecacheDriver::doctrineSet($emName, $emConnection['cache_config']['file']['dir'], 3600);
                            $cache = FilecacheDriver::doctrineGet($emName);
                            // $cache = new FilesystemAdapter(namespace: $emName, directory: $emConnection['cache_config']['file']['dir']);
                        }
                    }

                    $emConfig = ORMSetup::createAttributeMetadataConfig(
                        $emConnection['metadata_dirs'],
                        $emConnection['dev_mode'],
                        null,
                        $cache
                    );

                    if (isset($emConnection['schema_filter_expression'])) {
                        $emConfig->setSchemaAssetsFilter(static function (string $assetName) use ($emConnection): bool {
                            return (bool) preg_match($emConnection['schema_filter_expression'], $assetName);
                        });
                    }

                    return new EntityManager(
                        DriverManager::getConnection(
                            Config::get($configFileName)['dbal']['connections'][$emConnection['connection']]
                        )
                        , $emConfig
                    );

                });
            }
        }
    }

    /**
     * Get a Doctrine Entity Manager or DBAL Connection
     * The config key in this case needs to be entitymanager.name or dbal.name
     */
    public static function get(?string $configKey = null): EntityManager|Connection
    {
        return self::$container->get('drivers.database.doctrine.' . $configKey ?? 'entitymanager.primary');
    }

}