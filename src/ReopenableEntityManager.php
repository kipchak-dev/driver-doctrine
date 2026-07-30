<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine;

use Closure;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use ReflectionProperty;
use Throwable;

/**
 * An EntityManager decorator that can transparently replace its inner
 * EntityManager once Doctrine has closed it.
 *
 * Why: in long-running worker runtimes (FrankenPHP worker mode, RoadRunner,
 * Swoole, ReactPHP, ...) a single EntityManager serves many requests, and any
 * exception that escapes a unit of work — a failed flush(), an
 * integrity-constraint violation, a deadlock — makes Doctrine close it. A
 * closed EntityManager is terminal: it cannot be reopened, and every service
 * that captured a reference to it keeps failing with "The EntityManager is
 * closed." until the worker process recycles. Swapping a fresh EntityManager
 * into a DI container does not help either, because already-constructed
 * services still hold the dead instance.
 *
 * This decorator solves that by being the stable object everyone holds: when
 * the inner EntityManager is closed, {@see reopenIfClosed()} replaces it with
 * a fresh one built by the factory (by default over the same DBAL connection
 * and ORM configuration), healing every holder of the decorator at once —
 * same object, new guts. Call it once at the top of each request/job:
 *
 *     $em->reopenIfClosed();
 *
 * Reopening also:
 *  - rolls back (or closes, if the socket is dead) a connection left
 *    mid-transaction by the failure, so the next unit of work starts clean;
 *  - carries over the enabled query filters and their parameters, which live
 *    on the per-EntityManager FilterCollection and would otherwise silently
 *    switch off (e.g. a soft-delete filter) after the swap.
 */
final class ReopenableEntityManager extends EntityManagerDecorator
{
    /** @var Closure(EntityManagerInterface): EntityManagerInterface */
    private readonly Closure $factory;

    /**
     * @param EntityManagerInterface $wrapped The initial (open) EntityManager.
     * @param callable|null $factory Builds the replacement EntityManager; receives
     *                               the closed one so it can reuse its connection
     *                               and configuration. Defaults to rebuilding over
     *                               the same connection, configuration and event
     *                               manager.
     */
    public function __construct(EntityManagerInterface $wrapped, ?callable $factory = null)
    {
        parent::__construct($wrapped);

        $this->factory = $factory !== null
            ? $factory(...)
            : static fn (EntityManagerInterface $closed): EntityManagerInterface => new EntityManager(
                $closed->getConnection(),
                $closed->getConfiguration(),
                $closed->getEventManager(),
            );
    }

    /**
     * Replace the inner EntityManager with a fresh one if it has been closed.
     *
     * @return bool True when a replacement happened, false when the inner
     *              EntityManager was still open (no-op).
     */
    public function reopenIfClosed(): bool
    {
        if ($this->wrapped->isOpen()) {
            return false;
        }

        $closed = $this->wrapped;

        // A failed flush can leave the underlying connection mid-transaction.
        // Unwind it so the replacement does not inherit a dangling transaction;
        // if the rollback itself fails (dead socket), close the connection —
        // DBAL is lazy and opens a fresh one on the next query.
        $connection = $closed->getConnection();
        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            $connection->close();
        }

        $fresh = ($this->factory)($closed);

        // Filters registered on the (shared) Configuration survive the swap,
        // but which filters are ENABLED — and their parameters — live on the
        // per-EntityManager FilterCollection. Carry that state over so a
        // filter such as soft-delete does not silently switch off.
        foreach ($closed->getFilters()->getEnabledFilters() as $name => $filter) {
            $this->copyFilterParameters($filter, $fresh->getFilters()->enable($name));
        }

        $this->wrapped = $fresh;

        return true;
    }

    /**
     * The EntityManager currently being delegated to. Mainly useful in tests
     * and diagnostics; application code should keep holding the decorator.
     */
    public function getWrappedEntityManager(): EntityManagerInterface
    {
        return $this->wrapped;
    }

    /**
     * SQLFilter keeps its parameters in a private array with no public
     * accessor for the full set, so copy it verbatim via reflection. The
     * entries are immutable value objects, safe to share between instances.
     */
    private function copyFilterParameters(SQLFilter $from, SQLFilter $to): void
    {
        $parameters = new ReflectionProperty(SQLFilter::class, 'parameters');
        $parameters->setValue($to, $parameters->getValue($from));
    }
}
