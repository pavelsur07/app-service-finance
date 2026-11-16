<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Lock;

use Doctrine\DBAL\Connection;

/** Caller verifies connection ownership before acquiring the shared import lock. */
final readonly class MoySkladCatalogLock
{
    public function __construct(private Connection $db)
    {
    }

    public function tryAcquire(string $connectionId): bool
    {
        return $this->tryAcquireIn('moysklad-catalog', $connectionId);
    }

    public function tryAcquireSync(string $connectionId): bool
    {
        return $this->tryAcquireIn('moysklad-catalog-sync', $connectionId);
    }

    private function tryAcquireIn(string $namespace, string $connectionId): bool
    {
        return in_array($this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace),hashtext(:key))',
            ['namespace' => $namespace, 'key' => strtolower($connectionId)]), [true, 1, '1', 't'], true);
    }

    public function release(string $connectionId): void
    {
        $this->releaseIn('moysklad-catalog', $connectionId);
    }

    public function releaseSync(string $connectionId): void
    {
        $this->releaseIn('moysklad-catalog-sync', $connectionId);
    }

    private function releaseIn(string $namespace, string $connectionId): void
    {
        try {
            $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace),hashtext(:key))',
                ['namespace' => $namespace, 'key' => strtolower($connectionId)]);
        } catch (\Throwable) {
            // A lost database session has already released its session lock.
        }
    }
}
