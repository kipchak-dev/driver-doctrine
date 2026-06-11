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
                'schema_filter_expression' => '"~^(?!mamluk_)~"',

                // Optional: register custom DQL functions on this entity manager.
                // See "Custom DQL Functions" below for details.
                // 'custom_dql_functions' => [
                //     'numeric' => [
                //         'JSON_LENGTH' => \DoctrineExtensions\Query\Mysql\JsonLength::class,
                //     ],
                // ],
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

### Migrations CLI

By default, migrations are loaded from your project path:

`<your-project-root>/migrations/database`

This path is resolved from the consuming project (the app that installs this package), not from `vendor/kipchak/driver-doctrine`.

`migrations:up` supports `--file` as:

- A single SQL file path (absolute or relative to current working directory)
- A directory path (runs all `.sql` files recursively)
- A file or directory relative to `<your-project-root>/migrations/database`

`migrations:down` supports `--file` as:

- A single SQL file path (absolute or relative to current working directory)
- A file relative to `<your-project-root>/migrations/database`

`migrations:down --file=<directory>` is not supported. If `--file` is omitted, it reverts the latest applied migration.

## Custom DQL Functions

Doctrine ORM ships a minimal DQL grammar — vendor-specific helpers such as `REGEXP`, `MATCH … AGAINST`, `STR_TO_DATE`, `GROUP_CONCAT`, JSON helpers, and so on must be registered on the EntityManager's `Configuration` **before** the EntityManager is constructed (Doctrine locks the configuration once the EM is built).

This driver exposes that registration through an optional per-entity-manager `custom_dql_functions` block in `kipchak.doctrine.php`. The block has three families, matching Doctrine's three registration methods:

| Family     | Doctrine API                  | Use for                                       |
|------------|-------------------------------|-----------------------------------------------|
| `numeric`  | `addCustomNumericFunction()`  | functions that return a number (`BIT_COUNT`, `DAY`, …)         |
| `string`   | `addCustomStringFunction()`   | functions that return a string (`GROUP_CONCAT`, `REGEXP`, `MATCH_AGAINST`, …) |
| `datetime` | `addCustomDatetimeFunction()` | functions that return a date/datetime (`STR_TO_DATE`, `DATE_FORMAT`, …)         |

Each family maps DQL function name → fully qualified class name. The class must extend `Doctrine\ORM\Query\AST\Functions\FunctionNode`.

### Example: enable a few MySQL helpers via beberlei/doctrineextensions

The most popular ready-made bundle of function nodes is `beberlei/doctrineextensions`:

```
composer require beberlei/doctrineextensions
```

Then, in your `kipchak.doctrine.php` entity-manager block:

```php
'custom_dql_functions' => [
    'numeric' => [
        'BIT_COUNT' => \DoctrineExtensions\Query\Mysql\BitCount::class,
    ],
    'string' => [
        'GROUP_CONCAT'  => \DoctrineExtensions\Query\Mysql\GroupConcat::class,
        'MATCH_AGAINST' => \DoctrineExtensions\Query\Mysql\MatchAgainst::class,
        'REGEXP'        => \DoctrineExtensions\Query\Mysql\Regexp::class,
    ],
    'datetime' => [
        'STR_TO_DATE' => \DoctrineExtensions\Query\Mysql\StrToDate::class,
    ],
],
```

You can now use these in DQL:

```php
$em->createQuery(
    "SELECT a FROM App\Entities\Article a
     WHERE MATCH_AGAINST(a.title, a.body, 'kipchak') > 0"
);
```

### JSON helpers

`beberlei/doctrineextensions` does **not** ship MySQL `JSON_*` function nodes. Two clean options:

1. **`scienta/doctrine-json-functions`** — a dedicated JSON-functions package covering `JSON_LENGTH`, `JSON_EXTRACT`, `JSON_CONTAINS`, etc. Register the classes the same way:

   ```php
   'custom_dql_functions' => [
       'numeric' => [
           'JSON_LENGTH' => \Scienta\DoctrineJsonFunctions\Query\AST\Functions\Mysql\JsonLength::class,
       ],
   ],
   ```

2. **Hand-roll your own.** A `FunctionNode` for a single-arg MySQL function is ~25 lines:

   ```php
   namespace App\Doctrine\DqlFunctions;

   use Doctrine\ORM\Query\AST\Functions\FunctionNode;
   use Doctrine\ORM\Query\Parser;
   use Doctrine\ORM\Query\SqlWalker;
   use Doctrine\ORM\Query\TokenType;

   final class JsonLength extends FunctionNode
   {
       private $value;

       public function parse(Parser $parser): void
       {
           $parser->match(TokenType::T_IDENTIFIER);
           $parser->match(TokenType::T_OPEN_PARENTHESIS);
           $this->value = $parser->StringPrimary();
           $parser->match(TokenType::T_CLOSE_PARENTHESIS);
       }

       public function getSql(SqlWalker $sqlWalker): string
       {
           return 'JSON_LENGTH(' . $this->value->dispatch($sqlWalker) . ')';
       }
   }
   ```

   Register under the `numeric` family (`JSON_LENGTH` returns an integer).

### Notes

- The block is **fully optional and additive**. Omit it entirely (or leave any family empty) and behaviour is identical to previous releases.
- Function names are case-insensitive in DQL but Doctrine stores them upper-cased internally — prefer `JSON_LENGTH` over `json_length` for clarity.
- Functions are registered per entity manager, so different EMs can advertise different DQL grammars.
- You can mix vendored function nodes (e.g. `beberlei/doctrineextensions`, `scienta/doctrine-json-functions`) with your own — just point at any `FunctionNode` subclass.

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
