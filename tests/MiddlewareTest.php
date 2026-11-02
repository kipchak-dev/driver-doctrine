<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Kipchak\Driver\Doctrine\Library\Middleware;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $connects = [];

    protected function tearDown(): void
    {
        Middleware::remove('first');
        Middleware::remove('second');
    }

    private function recording(string $label): DriverMiddleware
    {
        $connects = &$this->connects;

        return new class ($label, $connects) implements DriverMiddleware {
            /** @param list<string> $connects */
            public function __construct(private string $label, private array &$connects)
            {
            }

            public function wrap(Driver $driver): Driver
            {
                $label = $this->label;
                $connects = &$this->connects;

                return new class ($driver, $label, $connects) extends AbstractDriverMiddleware {
                    /** @param list<string> $connects */
                    public function __construct(Driver $driver, private string $label, private array &$connects)
                    {
                        parent::__construct($driver);
                    }

                    public function connect(array $params): Driver\Connection
                    {
                        $this->connects[] = $this->label;

                        return parent::connect($params);
                    }
                };
            }
        };
    }

    public function testWithoutMiddlewareConnectionsAreCreatedAsBefore(): void
    {
        $this->assertNull(Middleware::configuration('primary'));
    }

    public function testMiddlewareWrapsConnectionsInTheOrderPushedWithTheConnectionName(): void
    {
        $names = [];
        Middleware::push('first', $this->recording('first'));
        Middleware::push('second', function (string $connection) use (&$names): DriverMiddleware {
            $names[] = $connection;

            return $this->recording('second');
        });

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], Middleware::configuration('primary'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT 1'));

        $this->assertSame(['primary'], $names);
        $this->assertSame(['first', 'second'], $this->connects);
    }

    public function testPushingANameAgainReplacesIt(): void
    {
        Middleware::push('first', $this->recording('old'));
        Middleware::push('first', $this->recording('new'));

        DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], Middleware::configuration('primary'))->fetchOne('SELECT 1');

        $this->assertSame(['new'], $this->connects);
    }
}
