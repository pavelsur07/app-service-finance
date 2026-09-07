<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Inventory\Enum\StockSnapshotMappingStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Варианты, по которым приходят остатки, но листинг для них не найден.
 *
 * Разделяет две принципиально разные величины:
 *
 * - newVariants — варианты, у которых ТЕКУЩИЙ эпизод непривязанности начался
 *   внутри окна. Это то, на что имеет смысл алертить: появилось новое расхождение
 *   между выгрузкой остатков и каталогом маркетплейса.
 *
 *   Считается именно эпизод, а не первое появление за всю историю. Вариант могли
 *   разобрать, а потом он снова выпал из каталога — по общему минимуму такое
 *   повторное расхождение прошло бы молча. Началом эпизода считается первый
 *   непривязанный день ПОСЛЕ последнего дня, когда вариант был привязан.
 * - standingVariants / standingRows — накопленный остаток текущих эпизодов. Он остаётся
 *   красным до ручного разбора каждой позиции, поэтому годится как метрика для
 *   дашборда, но не как условие алерта: гейт на нём был бы вечно красным, а
 *   вечно красный гейт обесценивает канал целиком.
 *
 * Список компаний передаётся вызывающим кодом и берётся из активных подключений,
 * поэтому запрос ограничен явным набором и чужие компании не сканирует.
 */
final readonly class UnmappedStockVariantsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<array{companyId: string, source: string, newVariants: int, standingVariants: int, standingRows: int, firstSeen: string, lastSeen: string}>
     */
    public function execute(array $companyIds, \DateTimeImmutable $newSince): array
    {
        if ([] === $companyIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'WITH last_mapped AS (
                SELECT
                    m.company_id,
                    m.source,
                    m.source_sku,
                    MAX(m.snapshot_date) AS last_mapped_day
                FROM inventory_stock_snapshots m
                WHERE m.company_id IN (:companyIds)
                  AND m.mapping_status = :mappedStatus
                GROUP BY m.company_id, m.source, m.source_sku
             ),
             unmapped AS (
                SELECT
                    s.company_id,
                    s.source,
                    s.source_sku,
                    MIN(s.snapshot_date) AS first_day,
                    MAX(s.snapshot_date) AS last_day,
                    count(*) AS rows_count
                FROM inventory_stock_snapshots s
                LEFT JOIN last_mapped lm
                    ON lm.company_id = s.company_id
                   AND lm.source = s.source
                   AND lm.source_sku = s.source_sku
                WHERE s.company_id IN (:companyIds)
                  AND s.mapping_status IS DISTINCT FROM :mappedStatus
                  -- Только текущий эпизод: дни после того, как вариант в последний
                  -- раз был привязан. Иначе разобранное и снова сломавшееся
                  -- расхождение унаследовало бы старую дату и прошло бы молча.
                  AND (lm.last_mapped_day IS NULL OR s.snapshot_date > lm.last_mapped_day)
                GROUP BY s.company_id, s.source, s.source_sku
             )
             SELECT
                u.company_id AS company_id,
                u.source AS source,
                count(*) FILTER (WHERE u.first_day >= :newSince) AS new_variants,
                count(*) AS standing_variants,
                sum(u.rows_count) AS standing_rows,
                to_char(MIN(u.first_day), \'YYYY-MM-DD\') AS first_seen,
                to_char(MAX(u.last_day), \'YYYY-MM-DD\') AS last_seen
             FROM unmapped u
             GROUP BY u.company_id, u.source
             ORDER BY u.company_id, u.source',
            [
                'companyIds' => array_values(array_unique($companyIds)),
                // Одно определение «не привязана» на весь модуль: см. SessionsToRenormalizeQuery.
                'mappedStatus' => StockSnapshotMappingStatus::Mapped->value,
                'newSince' => $newSince->format('Y-m-d'),
            ],
            ['companyIds' => ArrayParameterType::STRING],
        );

        return array_map(
            static fn (array $row): array => [
                'companyId' => (string) $row['company_id'],
                'source' => (string) $row['source'],
                'newVariants' => (int) $row['new_variants'],
                'standingVariants' => (int) $row['standing_variants'],
                'standingRows' => (int) $row['standing_rows'],
                'firstSeen' => (string) $row['first_seen'],
                'lastSeen' => (string) $row['last_seen'],
            ],
            $rows,
        );
    }
}
