<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Lock;

use Doctrine\DBAL\Connection;

/**
 * Сериализует импорт выписок в один денежный счёт. Блокировка сессионная
 * (`pg_try_advisory_lock`), а не транзакционная: файловый импорт коммитит пачки по
 * отдельности, и транзакционная снималась бы на первом commit. Она же lease задания:
 * умер воркер — закрылось соединение — PostgreSQL снял блокировку.
 */
final readonly class CashImportAccountLock
{
    public function __construct(private Connection $connection)
    {
    }

    public function tryAcquire(string $companyId, string $moneyAccountId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))',
            ['namespace' => 'cash_import_account', 'key' => $companyId.':'.$moneyAccountId],
        );
    }

    public function release(string $companyId, string $moneyAccountId): void
    {
        $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))',
            ['namespace' => 'cash_import_account', 'key' => $companyId.':'.$moneyAccountId],
        );
    }
}
