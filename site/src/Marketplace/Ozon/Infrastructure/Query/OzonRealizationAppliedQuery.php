<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Применена ли «Реализация» за месяц в учёт независимо от того, как это произошло: автообработкой или вручную
 * кнопкой «Применить выручку». Доказательство — строки в `marketplace_ozon_realizations` документа, чья первичная
 * обработка дошла до конца (`records_created > 0` пишется в самом финале): строки вставляются пакетами без общей
 * транзакции, и частичный результат упавшей обработки применённым считаться не может.
 */
final readonly class OzonRealizationAppliedQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function isApplied(string $companyId, \DateTimeImmutable $monthStart): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM marketplace_ozon_realizations r
             INNER JOIN marketplace_raw_documents d ON d.id = r.raw_document_id
             WHERE r.company_id = :companyId AND r.period_from >= :from AND r.period_to <= :to AND d.records_created > 0
             LIMIT 1',
            [
                'companyId' => $companyId,
                'from' => $monthStart->format('Y-m-d'),
                'to' => $monthStart->modify('last day of this month')->format('Y-m-d'),
            ],
        );
    }
}
