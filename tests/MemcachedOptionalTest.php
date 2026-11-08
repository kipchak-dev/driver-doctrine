<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use DI\Container;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Doctrine\Doctrine;
use Kipchak\Driver\Doctrine\Tests\Fixtures\Widget;
use Kipchak\Driver\Filecache\Filecache;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Driver\Memcached\Memcached;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * kipchak/driver-memcached is a suggestion, not a requirement, and this package's own dependencies leave it out, so
 * these tests run without it. The driver's absence is checked first: the rest would mean nothing with it installed.
 */
final class MemcachedOptionalTest extends TestCase
{
    private string $database;

    private string $cache;

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir() . '/doctrine-mc-' . bin2hex(random_bytes(4)) . '.db';
        $this->cache = sys_get_temp_dir() . '/doctrine-mc-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
        if (is_dir($this->cache)) {
            exec('rm -rf ' . escapeshellarg($this->cache));
        }
    }

    public function testTheMemcachedDriverIsNotInstalled(): void
    {
        self::assertFalse(class_exists(Memcached::class));
    }

    public function testTheFileCacheWorksWithoutTheMemcachedDriver(): void
    {
        $this->boot('file');
        $em = Doctrine::get('entitymanager.primary');
        $em->getConnection()->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(50) NOT NULL UNIQUE)');

        $widget = new Widget('one');
        $em->persist($widget);
        $em->flush();
        $em->clear();

        self::assertSame('one', $em->find(Widget::class, $widget->getId())->getName());
    }

    public function testTheMemcachedStoreWithoutTheDriverFailsAtBootNamingThePackage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer require kipchak/driver-memcached');

        // The entity manager is never asked for: the failure is at boot.
        $this->boot('memcached');
    }

    public function testTheFailureAlsoAppliesInDevelopment(): void
    {
        $this->expectException(RuntimeException::class);

        $this->boot('memcached', devMode: true);
    }

    public function testControlTheMemcachedStoreWithTheDriverInstalledPasses(): void
    {
        $this->expectNotToPerformAssertions();

        Doctrine::requireMemcachedDriver('primary', ['cache' => ['enabled' => true, 'store' => 'memcached']], installed: true);
    }

    public function testControlADisabledCacheNamingMemcachedPasses(): void
    {
        $this->expectNotToPerformAssertions();

        Doctrine::requireMemcachedDriver('primary', ['cache' => ['enabled' => false, 'store' => 'memcached']], installed: false);
    }

    public function testControlADisabledOrmNamingMemcachedBoots(): void
    {
        $this->boot('memcached', ormEnabled: false);

        self::assertFalse(class_exists(Memcached::class));
    }

    private function boot(string $store, bool $devMode = false, bool $ormEnabled = true): void
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', [
            'kipchak.api' => ['name' => 'test', 'loglevel' => \Monolog\Level::Error],
            'kipchak.doctrine' => [
                'dbal' => ['enabled' => true, 'connections' => ['local' => ['driver' => 'pdo_sqlite', 'path' => $this->database]]],
                'orm' => ['enabled' => $ormEnabled, 'entity_managers' => ['primary' => [
                    'connection' => 'local',
                    'dev_mode' => $devMode,
                    'metadata_dirs' => [__DIR__ . '/Fixtures'],
                    'cache' => ['enabled' => true, 'store' => $store],
                    'cache_config' => ['file' => ['dir' => $this->cache], 'memcached' => ['pool' => 'cache']],
                ]]],
            ],
        ]);
        Logger::initialise($container);
        Filecache::initialise($container);
        Doctrine::initialise($container);
    }
}
