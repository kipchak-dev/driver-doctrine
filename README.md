# Doctrine Driver for Kipchak

This driver is based on Symfony's Doctrine DBAL and ORM components (v4).

It is used to interact with MySQL, PGSQL and Oracle databases and supports multiple connections.

#### Composer Package 

`kipchak/driver-doctrine`

#### Sample Config File:

This config file should be placed in your Kipchak project's config directory, as in the starter
project at https://1x.ax/mamluk/kipchak/starter/~files/master/config/kipchak.doctrine.php.

The config file should look like this (also avalable in sample.config.php):

```
<?php

use function Kipchak\Core\env;

return
[
    'dbal' => [
        'enabled' => false,
        'connections' => [
            // https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/configuration.html
                'primary' => [
                    'dbname' => 'mydb',
                    'user' => env('DB_USER', 'api'),
                    'password' =>  env('DB_PASSWORD', 'api'),
                    'host' => env('DB_HOST', 'mysql'),
                    'port' => env('DB_PORT', 3306),
                    'driver' => 'pdo_mysql',
                    'charset' => 'utf8'
                ]
            ]
    ],

    'orm' => [
        'enabled' => false,
        'entity_managers' => [
            'primary' => [
                // Enables or disables Doctrine metadata caching
                // for either performance or convenience during development.
                'dev_mode' => (bool) env('DEBUG', true),

                // List of paths where Doctrine will search for metadata.
                // Metadata can be either YML/XML files or PHP classes annotated
                // with comments or PHP8 attributes (effectively Doctrine's database entities)
                'metadata_dirs' => [
                    realpath(__DIR__ . '/../api/Entities/Doctrine/Primary')
                ],
                'metadata_format' => 'attributes', // attributes or annotations. Annotations are no longer supported > 1.0.
                'connection' => 'primary', // Name of connection from 'dbal' above to use for the ORM
                'cache' => [
                    'enabled' => true, // Kipchak will only look at this if dev_mode = false
                    'store' => 'memcached', // file or memcached.
                ],
                'cache_config' => [
                    'memcached' => [
                        // Pool where Doctrine will cache the processed metadata when 'dev_mode' is false
                        'pool' => 'cache'
                    ],
                    'file' => [
                        // Path where Doctrine will cache the processed metadata when 'dev_mode' is false
                        'dir' => '/tmp',
                    ]
                ],
                // This will make sure Doctrine ORM does not manage tables beginning with mamluk_
                'schema_filter_expression' => '"~^(?!mamluk_)~"'
            ],
        ]
    ]
];

```

## How to use it?

Install it via composer: `composer require kipchak/driver-doctrine`.

### Example Usage

```php
// Load the HTTP driver.
$entityManager = Kipchak\Driver\Doctrine\Doctrine::get('entitymanager.primary'); // entitymanager.primary is a reference to the primary entity manager defined in the config file.
// Use the $entityManager client as normal.
```

You can also use the doctrine cli baked into the starter project at https://1x.ax/mamluk/kipchak/starter. If you have created
your project from the starter project, simply run `php bin/doctrine` to access the CLI after making sure you have installed the
driver via composer and enabeld it in the config file.

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems.

They provide a standardized interface for interacting with different data sources, allowing developers to
focus on building their applications rather than dealing with the complexities of each data source.

Drivers were introduced as a part of the Kipchak 2.0 release.

Drivers are basically Container Dependencies injected into Kipchak's Service Container.

## How do Kipchak Drivers work?

Some Kipchak drivers are wired into the Service Container via a config file (where required).

If applicable, you will find a sample config file in this repository as well as in the starter project at https://1x.ax/mamluk/kipchak/starter.

Each driver defines an implementation of Kipchak's Driver Interface. Where applicable, the dependency may
accept a parameter for a specific instance of the driver specified as a config property, for instance, in the case
of multiple database connections or S3 buckets.

So you may access the driver by invoking \Mamluk\Kipchak\Driver\Memcached\Memcached::get('cache'), where
`cache` is the name of one of the memcached connection pools specified in the config file.