<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\MarketplaceAds\Application\DTO\AdEfficiencyItemDTO;
use App\MarketplaceAds\Application\DTO\AdEfficiencyPageDTO;
use Doctrine\DBAL\Connection;

/**
 * Read-model «Эффективность рекламы»: SKU × выручка × рекламные затраты × ДРР %.
 *
 * Живёт в MarketplaceAds, но читает напрямую из таблиц Marketplace (marketplace_sales,
 * marketplace_listings) и собственных (marketplace_ad_documents, marketplace_ad_document_lines).
 * Для read-only агрегата это приемлемо — см. прецедент ListingSalesAggregateQuery.
 *
 * DBAL, не ORM. Денежные значения наружу — decimal-строки (bcmath-compatible).
 */
final class AdEfficiencyQuery
{
    private const ALLOWED_SORT_BY = ['sku', 'title', 'revenue', 'adSpend', 'drrPercent'];
    private const ALLOWED_SORT_DIR = ['asc', 'desc'];
    private const DEFAULT_SORT_BY = 'revenue';
    private const DEFAULT_SORT_DIR = 'desc';

    private const SORT_COLUMNS = [
        'sku'        => 'l.marketplace_sku',
        'title'      => 'l.name',
        'revenue'    => 'revenue',
        'adSpend'    => 'ad_spend',
        'drrPercent' => 'drr_percent',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getPage(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $marketplace,
        int $page,
        int $pageSize,
        string $sortBy = self::DEFAULT_SORT_BY,
        string $sortDir = self::DEFAULT_SORT_DIR,
    ): AdEfficiencyPageDTO {
        $page = max(1, $page);
        $pageSize = max(10, min(100, $pageSize));
        $sortBy = in_array($sortBy, self::ALLOWED_SORT_BY, true) ? $sortBy : self::DEFAULT_SORT_BY;
        $sortDir = in_array(strtolower($sortDir), self::ALLOWED_SORT_DIR, true)
            ? strtolower($sortDir)
            : self::DEFAULT_SORT_DIR;

        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $params = [
            'companyId' => $companyId,
            'periodFrom' => $fromStr,
            'periodTo' => $toStr,
        ];
        if (null !== $marketplace) {
            $params['marketplace'] = $marketplace;
        }

        $mpSalesFilter = null !== $marketplace ? 'AND s.marketplace = :marketplace' : '';
        $mpAdsFilter = null !== $marketplace ? 'AND ad.marketplace = :marketplace' : '';
        $mpSalesFilterNoAlias = null !== $marketplace ? 'AND marketplace = :marketplace' : '';

        $baseListingsCte = <<<SQL
            base_listings AS (
                SELECT s.listing_id
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.sale_date BETWEEN :periodFrom AND :periodTo
                  {$mpSalesFilter}
                GROUP BY s.listing_id
                UNION
                SELECT adl.listing_id
                FROM marketplace_ad_document_lines adl
                JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
                WHERE ad.company_id = :companyId
                  AND ad.report_date BETWEEN :periodFrom AND :periodTo
                  {$mpAdsFilter}
                GROUP BY adl.listing_id
            )
            SQL;

        $salesCte = <<<SQL
            sales_agg AS (
                SELECT listing_id, SUM(total_revenue) AS revenue
                FROM marketplace_sales
                WHERE company_id = :companyId
                  AND sale_date BETWEEN :periodFrom AND :periodTo
                  {$mpSalesFilterNoAlias}
                GROUP BY listing_id
            )
            SQL;

        $adsCte = <<<SQL
            ads_agg AS (
                SELECT adl.listing_id, SUM(adl.cost) AS ad_spend
                FROM marketplace_ad_document_lines adl
                JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
                WHERE ad.company_id = :companyId
                  AND ad.report_date BETWEEN :periodFrom AND :periodTo
                  {$mpAdsFilter}
                GROUP BY adl.listing_id
            )
            SQL;

        $total = (int) $this->connection->fetchOne(
            "WITH {$baseListingsCte} SELECT COUNT(*) FROM base_listings",
            $params,
        );

        $offset = ($page - 1) * $pageSize;
        $orderColumn = self::SORT_COLUMNS[$sortBy];
        $orderDirUpper = strtoupper($sortDir);
        $nullsPosition = 'ASC' === $orderDirUpper ? 'NULLS FIRST' : 'NULLS LAST';

        $pageSql = <<<SQL
            WITH
            {$baseListingsCte},
            {$salesCte},
            {$adsCte}
            SELECT
                l.id AS listing_id,
                l.marketplace_sku AS sku,
                l.name AS title,
                l.marketplace AS marketplace,
                COALESCE(sales_agg.revenue, 0) AS revenue,
                COALESCE(ads_agg.ad_spend, 0) AS ad_spend,
                CASE WHEN COALESCE(sales_agg.revenue, 0) > 0
                     THEN COALESCE(ads_agg.ad_spend, 0) / sales_agg.revenue * 100
                     ELSE NULL
                END AS drr_percent
            FROM base_listings bl
            JOIN marketplace_listings l ON l.id = bl.listing_id
            LEFT JOIN sales_agg ON sales_agg.listing_id = l.id
            LEFT JOIN ads_agg ON ads_agg.listing_id = l.id
            ORDER BY {$orderColumn} {$orderDirUpper} {$nullsPosition}, l.id ASC
            LIMIT :pageSize OFFSET :offset
            SQL;

        $pageParams = $params + [
            'pageSize' => $pageSize,
            'offset' => $offset,
        ];

        $rows = $this->connection->fetchAllAssociative($pageSql, $pageParams);

        $items = [];
        foreach ($rows as $row) {
            $items[] = new AdEfficiencyItemDTO(
                listingId: (string) $row['listing_id'],
                sku: (string) $row['sku'],
                title: null !== $row['title'] ? (string) $row['title'] : null,
                marketplace: (string) $row['marketplace'],
                revenue: (string) $row['revenue'],
                adSpend: (string) $row['ad_spend'],
                drrPercent: null !== $row['drr_percent'] ? (string) $row['drr_percent'] : null,
            );
        }

        $totalRevenueRaw = $this->connection->fetchOne(
            <<<SQL
            SELECT COALESCE(SUM(total_revenue), 0)
            FROM marketplace_sales
            WHERE company_id = :companyId
              AND sale_date BETWEEN :periodFrom AND :periodTo
              {$mpSalesFilterNoAlias}
            SQL,
            $params,
        );
        $totalRevenue = false === $totalRevenueRaw ? '0' : (string) $totalRevenueRaw;

        $totalAdSpendRaw = $this->connection->fetchOne(
            <<<SQL
            SELECT COALESCE(SUM(adl.cost), 0)
            FROM marketplace_ad_document_lines adl
            JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
            WHERE ad.company_id = :companyId
              AND ad.report_date BETWEEN :periodFrom AND :periodTo
              {$mpAdsFilter}
            SQL,
            $params,
        );
        $totalAdSpend = false === $totalAdSpendRaw ? '0' : (string) $totalAdSpendRaw;

        $totalDrrPercent = null;
        if (1 === bccomp($totalRevenue, '0', 4)) {
            // (totalAdSpend / totalRevenue) * 100, с запасом знаков для bcdiv
            $totalDrrPercent = bcmul(bcdiv($totalAdSpend, $totalRevenue, 8), '100', 4);
        }

        return new AdEfficiencyPageDTO(
            items: $items,
            total: $total,
            page: $page,
            pageSize: $pageSize,
            totalRevenue: $totalRevenue,
            totalAdSpend: $totalAdSpend,
            totalDrrPercent: $totalDrrPercent,
        );
    }
}
