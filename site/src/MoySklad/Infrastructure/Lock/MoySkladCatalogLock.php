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
        return in_array($this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace),hashtext(:key))',
            ['namespace' => 'moysklad-catalog', 'key' => strtolower($connectionId)]), [true, 1, '1', 't'], true);
    }

    public function release(string $connectionId): void
    {
        try {
            $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace),hashtext(:key))',
                ['namespace' => 'moysklad-catalog', 'key' => strtolower($connectionId)]);
        } catch (\Throwable) {
            // A lost database session has already released its session lock.
        }
    }
}
