<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query\Reconciliation;

use App\Marketplace\Ozon\Application\Reconciliation\DTO\RealizationTotals;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

/**
 * Итоги отчёта «Реализация» из `marketplace_ozon_realizations`. Нет строк — `null`:
 * отчёт за период не загружен, и это не то же самое, что нулевые продажи.
 */
final readonly class OzonRealizationTotalsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function fetch(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $currency = 'RUB'): ?RealizationTotals
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(r.id)                             AS rows_count,
                    COALESCE(SUM(r.total_amount), 0)        AS sales_amount,
                    COALESCE(SUM(r.quantity), 0)            AS sales_quantity,
                    COALESCE(SUM(r.return_amount), 0)       AS returns_amount,
                    COALESCE(SUM(r.return_quantity), 0)     AS returns_quantity
                FROM marketplace_ozon_realizations r
                WHERE r.company_id = :companyId
                  AND r.period_from >= :from
                  AND r.period_to <= :to
                SQL,
            [
                'companyId' => $companyId,
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        );

        if (false === $row || 0 === (int) $row['rows_count']) {
            return null;
        }

        return new RealizationTotals(
            Money::fromString((string) $row['sales_amount'], $currency),
            (int) $row['sales_quantity'],
            Money::fromString((string) $row['returns_amount'], $currency),
            (int) $row['returns_quantity'],
        );
    }
}
