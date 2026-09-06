<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Дата последнего снапшота остатков по каждой паре «компания + источник».
 *
 * Список компаний передаётся вызывающим кодом и берётся из активных подключений,
 * поэтому запрос ограничен явным набором и не сканирует чужие компании.
 *
 * Верхняя граница $asOf обязательна и совпадает с границей отчётного запроса
 * StockQtyByListingOnDateQuery. Без неё снапшот с датой из будущего попал бы в
 * MAX() и прошёл проверку свежести, которая ограничивает возраст только снизу, —
 * гейт стал бы зелёным при отсутствии актуальных данных.
 */
final readonly class LatestStockSnapshotDateQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $companyIds
     *
     * @return array<string, array<string, string>> companyId => [source => Y-m-d]
     */
    public function findLatestByCompanyIds(array $companyIds, \DateTimeImmutable $asOf): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                s.company_id AS company_id,
                s.source AS source,
                to_char(MAX(s.snapshot_date), \'YYYY-MM-DD\') AS snapshot_date
             FROM inventory_stock_snapshots s
             WHERE s.company_id IN (:companyIds)
               AND s.snapshot_date <= :asOf
             GROUP BY s.company_id, s.source',
            [
                'companyIds' => array_values(array_unique($companyIds)),
                'asOf' => $asOf->format('Y-m-d'),
            ],
            ['companyIds' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['company_id']][(string) $row['source']] = (string) $row['snapshot_date'];
        }

        return $result;
    }
}
