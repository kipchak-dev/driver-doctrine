# Doctrine Driver for Kipchak

This driver is based on Symfony's Doctrine DBAL and ORM components (v4).

It is used to interact with MySQL, PGSQL and Oracle databases and supports multiple connections.

#### Composer Package 

`kipchak/driver-doctrine`

#### Installation: doctrine/orm 4 is not yet a stable release

This driver uses doctrine/orm 4, which Doctrine has not released as a stable version: Packagist offers the
`4.0.x-dev` branch, and its newest stable release is 3.7. A project that accepts only stable packages, as the starter
does, therefore needs to allow that one package to be a development version. Kipchak CLI 1.2.1 and later do this for you:

```bash
vendor/bin/kipchak add doctrine
```

adds `"doctrine/orm": "^4@dev"` to your `composer.json` beside the driver, so that every other package stays stable.
Without the CLI, run:

```bash
composer require kipchak/driver-doctrine "doctrine/orm:^4@dev"
```

The `4.0.x-dev` branch moves with every `composer update doctrine/orm`; see "Maintaining this driver" below for how each
release of this driver is tested against it. ORM 4 also changes some APIs: `QueryBuilder::orderBy()` takes PHP 8.6's
`SortDirection` enum (`\SortDirection::Ascending`, polyfilled by `symfony/polyfill-php86`), not the string `'ASC'`.
When Doctrine releases ORM 4.0.0, change the constraint to `"doctrine/orm": "^4"`: Composer ranks `4.0.x-dev` above
`4.0.0`, so `^4@dev` would keep the development branch.

#### Sample Config File:

This config file should be placed in your Kipchak project's config directory, as in the examples
project at https://github.com/kipchak-dev/examples/blob/master/config/kipchak.doctrine.php.

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
    ],

    // migrations:up. Optional: these are the defaults. See "Migrations CLI" below.
    'migrations' => [
        'lock_timeout' => 300, // seconds to wait for the migration lock
        'strict' => false,     // true: an "already exists" error fails the migration
    ],
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

Each SQL file holds its up statements, then a line `-- Down` and the statements that undo them. Migrations are recorded
in the `schema_migrations` table; on PostgreSQL, SQLite and SQL Server each file runs in one transaction. They work on
SQLite from 1.54: earlier releases recorded each migration with `NOW()`, which SQLite does not have, and failed.

#### The migration lock (from 1.57)

`migrations:up` holds a lock for its whole run, so that two runs against one database, such as two pods starting at
once, cannot both find a migration unapplied and both execute it. The lock is taken before `schema_migrations` is
created or read and released when the run ends, whether it succeeded, failed or threw. The second run waits, then
finds the migrations applied and skips them. The log says so:

```
Taking the migration lock kipchak.migrations.shop.
Waiting up to 300 s for the migration lock kipchak.migrations.shop, which another run holds.
Obtained the migration lock kipchak.migrations.shop after 2.0 s.
Skipping 2026_01_01_000000_shelves.sql (already applied)
Released the migration lock kipchak.migrations.shop.
```

| Platform | Lock |
|---|---|
| MySQL, MariaDB | `GET_LOCK` / `RELEASE_LOCK`, named `kipchak.migrations.<database>` |
| PostgreSQL | `pg_advisory_lock` / `pg_advisory_unlock`, on a bigint key derived from `kipchak.migrations.<database>`; the wait is bounded with `lock_timeout` for that one statement |
| SQLite | None. SQLite allows one writer at a time and runs each migration in a transaction. |
| SQL Server and others | None. Do not start two runs at once. |

The name includes the database name, so runs against two databases on one server do not wait for each other. The lock
belongs to the command's connection: if the process dies, the database releases it when the connection closes.

If the lock is not obtained within `migrations.lock_timeout` seconds, the command prints
`Did not obtain the migration lock ... No migration was run.` and exits with an error, having created nothing. A
release that fails (`RELEASE_LOCK` answers 0 or NULL, or the connection is gone) is printed as a warning and does not
change the exit code.

#### Settings

Both are optional, under `migrations` in `config/kipchak.doctrine.php`:

| Setting | Default | Meaning |
|---|---|---|
| `migrations.lock_timeout` | `300` | Seconds to wait for the migration lock. `0` tries once without waiting. |
| `migrations.strict` | `false` | Whether an "already exists" error fails the migration (see below). |

#### "Already exists" errors and strict mode

When a statement fails with "already exists", "Duplicate column name" or "Duplicate key name", and `migrations.strict`
is off (the default), the statement is skipped, the file is recorded as applied, and a warning names the file and the
statement:

```
Warning: 2026_01_02_000000_books.sql: skipped a statement that was already applied (... Table 'books' already exists). Statement: CREATE TABLE books (id INT PRIMARY KEY, ...)
```

This lets a rerun recover from a migration that failed halfway on MySQL, which cannot roll back schema changes, and lets
a schema created by other means (for example `orm:schema-tool:create`) be followed by `migrations:up`.

With `migrations.strict` on, such an error fails the migration like any other: the file is rolled back where the
platform supports transactional schema changes (PostgreSQL, SQLite, SQL Server), it is not recorded, and the command
exits with an error.

The remaining risk: "already exists" is matched by name only, so a rerun that finds a column or index of the same name
with a different definition still records the statement as applied. Strict mode catches that.

On PostgreSQL the skip does not help: the failed statement aborts the file's transaction, so the next statement fails
and the file is rolled back, with strict mode on or off.

## DBAL middleware

From 1.51, DBAL driver middleware registered with `Kipchak\Driver\Doctrine\Library\Middleware::push()` applies to
every connection the driver creates, its DBAL connections and those of its entity managers. It is read when each
connection is created, on first use, so it can be registered after the driver is initialised. The OpenTelemetry
driver uses it to trace queries. Nothing changes until something is registered.

```php
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Kipchak\Driver\Doctrine\Library\Middleware;

// A DBAL middleware, or a callable that receives the connection's name and returns one.
Middleware::push('query-log', fn (string $connection): DriverMiddleware => new QueryLog($connection));
```

## Reopenable Entity Managers (long-running workers)

In long-running worker runtimes (FrankenPHP worker mode, RoadRunner, Swoole, ...)
a single EntityManager serves many requests. Doctrine permanently **closes** an
EntityManager whenever an exception escapes a unit of work — a failed `flush()`,
an integrity-constraint violation, a deadlock. A closed EntityManager cannot be
reopened, so every service still holding a reference to it fails with
`The EntityManager is closed.` on all subsequent requests until the worker
process recycles. Replacing the container entry does not help: services
constructed earlier keep their captured reference.

Opt in per entity manager in the config:

```php
'entity_managers' => [
    'primary' => [
        // ...
        'reopenable' => true,
    ],
],
```

`Doctrine::get('entitymanager.primary')` then returns a
`Kipchak\Driver\Doctrine\ReopenableEntityManager` — a
`Doctrine\ORM\Decorator\EntityManagerDecorator` that delegates every call to an
inner EntityManager it can replace. Because all your services hold the (stable)
decorator, swapping the inner instance heals every holder at once. Call this at
the top of each request in your worker loop:

```php
$em = Doctrine::get('entitymanager.primary');
if ($em instanceof Kipchak\Driver\Doctrine\ReopenableEntityManager) {
    $em->reopenIfClosed();
}
```

`reopenIfClosed()` is a no-op while the inner EntityManager is open. When it has
been closed, the decorator:

- rolls back any transaction the failure left dangling on the connection (or
  closes the connection if the socket is dead — DBAL lazily reconnects);
- builds a fresh EntityManager over the same connection and configuration (or
  via a custom factory passed to the constructor);
- carries the **enabled query filters and their parameters** over to the fresh
  instance, so e.g. a soft-delete filter does not silently switch off.

The flag is off by default; existing configurations keep receiving a plain
`EntityManager`. If you enable it, type-hint your services against
`Doctrine\ORM\EntityManagerInterface` rather than the concrete `EntityManager`.

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

## Release notes

### 1.57

- **`migrations:up` takes a lock for its whole run** (MySQL and MariaDB `GET_LOCK`, PostgreSQL `pg_advisory_lock`; none
  on SQLite), so two runs started at once no longer apply the same migration twice or skip each other's statements.
  New setting `migrations.lock_timeout`, default 300 seconds. See "The migration lock" above.
- **New setting `migrations.strict`**, default `false`. Off, nothing changes: an "already exists" error skips the
  statement, and the warning now names the migration file and the statement. On, that error fails the migration.
- **`migrations:up` works on PostgreSQL**: `schema_migrations` was created with a `DATETIME` column, a type PostgreSQL
  does not have, so the command failed there before applying anything. It now uses `TIMESTAMP` on PostgreSQL.
- `MigrationsUpCommand` takes an optional second constructor argument, the `migrations` settings; without it they are
  read from `config/kipchak.doctrine.php`. `bin/doctrine` is unchanged.
- doctrine/orm: the lock did not move, so there are no upstream changes to record.

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

The suite runs on SQLite without anything else. The migration lock tests also need MySQL 8.4 and PostgreSQL. Start
both in Docker:

```bash
docker run -d --name kipchak-test-mysql -e MYSQL_ROOT_PASSWORD=test -p 127.0.0.1:33061:3306 mysql:8.4
docker run -d --name kipchak-test-pgsql -e POSTGRES_PASSWORD=test -p 127.0.0.1:54331:5432 postgres:17-alpine
```

then name them in the environment:

```bash
DOCTRINE_TEST_MYSQL_HOST=127.0.0.1 DOCTRINE_TEST_MYSQL_PORT=33061 DOCTRINE_TEST_MYSQL_USER=root DOCTRINE_TEST_MYSQL_PASSWORD=test \
DOCTRINE_TEST_PGSQL_HOST=127.0.0.1 DOCTRINE_TEST_PGSQL_PORT=54331 DOCTRINE_TEST_PGSQL_USER=postgres DOCTRINE_TEST_PGSQL_PASSWORD=test \
vendor/bin/phpunit
```

Setting a server's `HOST` variable requests its tests, which then fail, rather than skip, if the server cannot be
reached. Without it they are skipped. Each test creates its own database and drops it afterwards; the user needs the
right to create databases. Remove the containers afterwards with
`docker rm -f kipchak-test-mysql kipchak-test-pgsql`.

## Maintaining this driver

doctrine/orm 4 is unreleased and its `4.0.x-dev` branch moves, so every release of this driver follows two rules:

1. **It is tested against the exact doctrine/orm 4.0.x-dev commit in `composer.lock`.** The suite covers entity mapping,
   the repository, DQL and the QueryBuilder, custom DQL functions, migrations up and down, the migration lock against
   MySQL and PostgreSQL (see "Running the tests"), and worker-mode
   EntityManager reset (`ReopenableEntityManager`). Each test has a negative control that shows it fails without what
   it checks.
2. **Whenever the lock moves to a newer 4.0.x-dev commit, Doctrine's changes between the two commits are read**: its
   `UPGRADE.md`, the changelog and the commit log, for breaking changes. The release note records the commit range and
   each change that affects the driver, or says that none does.

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
