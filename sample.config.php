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
                    //     \Pdo\Mysql::ATTR_SSL_CA => env('MYSQL_SSL_CA', '/etc/mysql-tls/ca.crt'),
                    //     \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
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

                    // Optional (default false): wrap this entity manager in a
                    // ReopenableEntityManager decorator. Recommended for
                    // long-running worker runtimes (FrankenPHP worker mode,
                    // RoadRunner, Swoole) where one EntityManager serves many
                    // requests: Doctrine permanently closes an EM when an
                    // exception escapes a unit of work, and every service still
                    // holding it then fails with "The EntityManager is closed."
                    // until the worker recycles. With this enabled, call
                    // Doctrine::get('entitymanager.primary')->reopenIfClosed()
                    // at the top of each request to swap in a fresh inner EM
                    // over the same connection and configuration. Enabled query
                    // filters and their parameters are carried across the swap.
                    // 'reopenable' => true,

                    // Optional: register custom DQL functions on this entity
                    // manager. Three families are supported (numeric, string,
                    // datetime) and map 1:1 to Doctrine's Configuration API.
                    // Each class must extend
                    // Doctrine\ORM\Query\AST\Functions\FunctionNode.
                    //
                    // The beberlei/doctrineextensions package ships
                    // ready-made nodes for many MySQL helpers (BIT_COUNT,
                    // GROUP_CONCAT, MATCH_AGAINST, REGEXP, STR_TO_DATE, …).
                    // For JSON_* helpers, use scienta/doctrine-json-functions
                    // or roll your own — see the README for examples.
                    //
                    // 'custom_dql_functions' => [
                    //     'numeric' => [
                    //         'BIT_COUNT' => \DoctrineExtensions\Query\Mysql\BitCount::class,
                    //     ],
                    //     'string' => [
                    //         'GROUP_CONCAT'  => \DoctrineExtensions\Query\Mysql\GroupConcat::class,
                    //         'MATCH_AGAINST' => \DoctrineExtensions\Query\Mysql\MatchAgainst::class,
                    //     ],
                    //     'datetime' => [
                    //         'STR_TO_DATE' => \DoctrineExtensions\Query\Mysql\StrToDate::class,
                    //     ],
                    // ],
                ],
            ]
        ]
    ];