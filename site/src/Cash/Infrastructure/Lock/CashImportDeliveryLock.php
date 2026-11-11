<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Lock;

use Doctrine\DBAL\Connection;

/** Session lease: dispatchers serialize per company without holding job row locks during I/O. */
final readonly class CashImportDeliveryLock
{
    public function __construct(private Connection $connection)
    {
    }

    public function tryAcquire(string $companyId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))', ['namespace' => 'cash_import_delivery', 'key' => $companyId]);
    }

    public function release(string $companyId): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))', ['namespace' => 'cash_import_delivery', 'key' => $companyId]);
    }
}
