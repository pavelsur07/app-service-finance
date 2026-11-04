<?php

declare(strict_types=1);

namespace App\Budget\Application;

use Doctrine\DBAL\Connection;

/**
 * Блокировка варианта бюджета до конца транзакции: смена статуса и сохранение ячеек одного
 * варианта идут по очереди. Порядок: вариант → его строки. Вне транзакции снимается сразу.
 */
final class BudgetVersionLock
{
    public static function acquire(Connection $connection, string $versionId): void
    {
        $connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            // UUID без учёта регистра: Postgres находит ту же строку, ключ блокировки должен совпасть.
            ['key' => 'budget_version:'.strtolower($versionId)],
        );
    }
}
