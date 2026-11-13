<?php

declare(strict_types=1);

namespace Kipchak\Driver\Doctrine\Tests\Support;

use Closure;
use Kipchak\Driver\Doctrine\Library\MigrationLock\MigrationLock;

/**
 * A lock that records what the command asks of it, for the unit tests of acquiring and releasing.
 */
final class FakeMigrationLock implements MigrationLock
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param bool $free whether the first, non-waiting attempt obtains the lock
     * @param bool $obtainable whether the waiting attempt obtains it
     * @param bool|\Throwable $release what release() answers, or throws
     * @param Closure|null $onAcquired runs once the lock is obtained
     */
    public function __construct(
        private readonly bool $free = true,
        private readonly bool $obtainable = true,
        private readonly bool|\Throwable $release = true,
        private readonly ?Closure $onAcquired = null,
    ) {
    }

    public function locks(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake-lock';
    }

    public function description(): string
    {
        return 'A fake lock.';
    }

    public function tryAcquire(): bool
    {
        $this->calls[] = 'try';
        if ($this->free && $this->onAcquired !== null) {
            ($this->onAcquired)();
        }

        return $this->free;
    }

    public function acquire(int $timeout): bool
    {
        $this->calls[] = "acquire({$timeout})";
        if ($this->obtainable && $this->onAcquired !== null) {
            ($this->onAcquired)();
        }

        return $this->obtainable;
    }

    public function release(): bool
    {
        $this->calls[] = 'release';
        if ($this->release instanceof \Throwable) {
            throw $this->release;
        }

        return $this->release;
    }
}
