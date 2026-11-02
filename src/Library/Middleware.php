<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Library;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;

/**
 * DBAL driver middleware applied to every connection the Doctrine driver creates: its DBAL connections and the
 * connections of its entity managers.
 *
 * For packages that act on all database calls, such as the OpenTelemetry driver:
 *
 *   Middleware::push('opentelemetry', fn (string $connection): DriverMiddleware => new Tracing($connection));
 *
 * Middleware is either a DBAL middleware or a callable that receives the connection name (for an entity manager,
 * the name of the DBAL connection it uses) and returns one. It is read when each connection is created, which
 * happens on first use, so it can be registered after the Doctrine driver is initialised. Middleware is applied
 * in the order pushed, the first outermost. Pushing a name again replaces it.
 */
final class Middleware
{
    /** @var array<string, DriverMiddleware|callable(string): DriverMiddleware> */
    private static array $middleware = [];

    /**
     * @param DriverMiddleware|callable(string): DriverMiddleware $middleware
     */
    public static function push(string $name, DriverMiddleware|callable $middleware): void
    {
        unset(self::$middleware[$name]);
        self::$middleware[$name] = $middleware;
    }

    public static function remove(string $name): void
    {
        unset(self::$middleware[$name]);
    }

    /**
     * @return array<string, DriverMiddleware|callable(string): DriverMiddleware>
     */
    public static function all(): array
    {
        return self::$middleware;
    }

    /**
     * A DBAL configuration with the registered middleware for the named connection, or null when none is
     * registered, so connections are created exactly as before.
     */
    public static function configuration(string $connection): ?Configuration
    {
        if (self::$middleware === []) {
            return null;
        }

        $middlewares = [];
        foreach (self::$middleware as $middleware) {
            $middlewares[] = $middleware instanceof DriverMiddleware ? $middleware : $middleware($connection);
        }

        // DBAL applies the last middleware outermost.
        return (new Configuration())->setMiddlewares(array_reverse($middlewares));
    }
}
