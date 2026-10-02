<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Применена ли «Реализация» за месяц в учёт (есть строки в `marketplace_ozon_realizations`), независимо от того,
 * как это произошло: автообработкой или вручную кнопкой «Применить выручку».
 */
final readonly class OzonRealizationAppliedQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function isApplied(string $companyId, \DateTimeImmutable $monthStart): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM marketplace_ozon_realizations
             WHERE company_id = :companyId AND period_from >= :from AND period_to <= :to
             LIMIT 1',
            [
                'companyId' => $companyId,
                'from' => $monthStart->format('Y-m-d'),
                'to' => $monthStart->modify('last day of this month')->format('Y-m-d'),
            ],
        );
    }
}
