# CouchDB Driver for Kipchak

This driver is based on the [PHP CouchDB library](https://1x.ax/mamluk/couchdb) from [Mamluk](https://mamluk.net).

It is used to interact with CouchDB databases.

#### Composer Package 

`kipchak/driver-doctrine`

#### Driver Name

`database.couchdb.__connection_name__`

#### Sample Config File:

This config file should be placed in your Kipchak project's config directory, as in the starter 
project at https://1x.ax/mamluk/kipchak/starter/~files/master/config and **must be named `kipchak.couchdb.php`**.

```
<?php

use function Mamluk\Kipchak\env;

return [
    'enabled' => true,
    'connections' => [
        'default' => [
            'host' => env('COUCHDB_HOST', 'http://couchdb'), # No trailing slash, please.
            'port' => getenv('COUCHDB_PORT') !== false ? (int) getenv('COUCHDB_PORT') : null,
            'username' => env('COUCHDB_USER', 'api'),
            'password' => env('COUCHDB_PASSWORD', 'api'),
            'database' => env('COUCHDB_DATABASE', 'api_database')
        ],
        // Add more connections here if you want to connect to multiple databases or servers.
        'another_connection' => [
            'host' => 'http://anothercouchdb',
            'port' => 5984,
            'username' => 'another_api',
            'password' => '',
            'database' => 'another_api_database'
        ]
    ]
];
```

## How to use it?

Install it via composer: `composer require kipchak/driver-couchdb`.

The dependency name of this driver is `database.couchdb.__connection_name__`.

### Example Usage

```
$cdb = $this->container->get('database.couchdb.default');
// Use the CouchDB ($cdb) client as you normally would.
```
See the methods available in the client here: https://1x.ax/mamluk/couchdb/~files/master/src/Database/Client.php

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems. 

They provide a standardized interface for interacting with different data sources, allowing developers to 
focus on building their applications rather than dealing with the complexities of each data source.

Drivers were introduced as a part of the Kipchak 2.0 release.

Drivers are basically Slim Dependencies injected into Kipchak's Service Container.

## How do Kipchak Drivers work?

Kipchak drivers are wired into the Service Container via a config file.

Each driver has a pre-defined dependency name which may be sufficed with a config property, for instance, in the case 
of multiple database connections or S3 buckets.

So you may access the driver by invoking `$this->container->get('database.couchdb.default'), where 
`database.couchdb.` is the name of the driver and the `default` is the name of the connection specifiedin the config file.

