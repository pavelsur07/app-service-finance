<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use App\Inventory\Application\DTO\StockOnDateResult;
use App\Inventory\Domain\StockSnapshotFreshnessPolicy;
use App\Inventory\Enum\StockStatus;
use Doctrine\DBAL\Connection;

final readonly class StockQtyByListingOnDateQuery
{
    public function __construct(
        private Connection $connection,
        private StockSnapshotFreshnessPolicy $freshnessPolicy,
    ) {
    }

    /**
     * Остатки по листингам на дату отчёта из последней подходящей сессии каждого источника.
     *
     * Снапшот старше порога свежести не участвует в расчёте: источник попадает в
     * staleSources, а его листинги — не в результат. Иначе остановившаяся загрузка
     * продолжала бы выглядеть здоровыми данными сколь угодно долго.
     *
     * LEFT JOIN нужен именно для этого: он оставляет строку с датой даже для
     * отброшенного источника, поэтому «данных нет» отличимо от «данные протухли»
     * без второго запроса.
     *
     * $marketplace сужает выборку до одного источника. Он обязателен там, где
     * вызывающий код сам отфильтрован по маркетплейсу: без него в отчёт по Ozon
     * попали бы остатки Wildberries.
     */
    public function execute(string $companyId, \DateTimeImmutable $reportDate, ?string $marketplace = null): StockOnDateResult
    {
        $params = [
            'companyId' => $companyId,
            'reportDate' => $reportDate->format('Y-m-d'),
            'status' => StockStatus::Available->value,
            'earliestAcceptableDate' => $this->freshnessPolicy->earliestAcceptableDate($reportDate)->format('Y-m-d'),
        ];

        $sourceFilter = '';
        if (null !== $marketplace) {
            $sourceFilter = ' AND candidate.source = :marketplace';
            $params['marketplace'] = $marketplace;
        }

        $rows = $this->connection->fetchAllAssociative(
            'WITH latest_sessions AS (
                SELECT DISTINCT ON (candidate.source)
                    candidate.source,
                    candidate.snapshot_session_id,
                    candidate.snapshot_date
                FROM inventory_stock_snapshots candidate
                WHERE candidate.company_id = :companyId
                  AND candidate.snapshot_date <= :reportDate
                  AND candidate.listing_id IS NOT NULL'.$sourceFilter.'
                ORDER BY
                    candidate.source,
                    candidate.snapshot_date DESC,
                    candidate.snapshot_at DESC,
                    candidate.snapshot_session_id DESC
             )
             SELECT
                latest.source AS source,
                to_char(latest.snapshot_date, \'YYYY-MM-DD\') AS snapshot_date,
                s.listing_id AS listing_id,
                SUM(s.quantity) AS stock_qty
             FROM latest_sessions latest
             LEFT JOIN inventory_stock_snapshots s
                ON s.snapshot_session_id = latest.snapshot_session_id
               AND s.source = latest.source
               AND s.company_id = :companyId
               AND s.status = :status
               AND s.listing_id IS NOT NULL
               AND latest.snapshot_date >= :earliestAcceptableDate
             GROUP BY latest.source, latest.snapshot_date, s.listing_id',
            $params,
        );

        $qtyByListingId = [];
        $snapshotDateBySource = [];
        $staleSources = [];

        foreach ($rows as $row) {
            $source = (string) $row['source'];
            $snapshotDate = (string) $row['snapshot_date'];

            if ($this->freshnessPolicy->isStale(new \DateTimeImmutable($snapshotDate), $reportDate)) {
                $staleSources[$source] = $snapshotDate;

                continue;
            }

            $snapshotDateBySource[$source] = $snapshotDate;

            if (null === $row['listing_id']) {
                continue;
            }

            $listingId = (string) $row['listing_id'];
            $qtyByListingId[$listingId] = ($qtyByListingId[$listingId] ?? 0.0) + (float) $row['stock_qty'];
        }

        foreach ($qtyByListingId as $listingId => $qty) {
            $qtyByListingId[$listingId] = round($qty, 3);
        }

        return new StockOnDateResult($qtyByListingId, $snapshotDateBySource, $staleSources);
    }
}
