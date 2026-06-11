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
                    'charset' => 'utf8',
                    // Optional: PDO driver options for SSL/TLS connections.
                    // Mount the MySQL CA certificate ConfigMap and reference the path here.
                    // 'driverOptions' => [
                    //     \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_SSL_CA', '/etc/mysql-tls/ca.crt'),
                    //     \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
                    // ],
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
                    'schema_filter_expression' => '"~^(?!mamluk_)~"',

                    // Optional: register custom DQL functions on this entity
                    // manager. Three families are supported (numeric, string,
                    // datetime) and map 1:1 to Doctrine's Configuration API.
                    // The classes themselves must extend
                    // Doctrine\ORM\Query\AST\Functions\FunctionNode. The
                    // beberlei/doctrineextensions package ships ready-made
                    // implementations for most MySQL JSON/REGEXP/MATCH
                    // helpers; you can also drop in your own.
                    //
                    // 'custom_dql_functions' => [
                    //     'numeric' => [
                    //         'JSON_LENGTH' => \DoctrineExtensions\Query\Mysql\JsonLength::class,
                    //     ],
                    //     'string' => [
                    //         'JSON_EXTRACT' => \DoctrineExtensions\Query\Mysql\JsonExtract::class,
                    //     ],
                    //     'datetime' => [
                    //         'STR_TO_DATE' => \DoctrineExtensions\Query\Mysql\StrToDate::class,
                    //     ],
                    // ],
                ],
            ]
        ]
    ];