<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Read-model «РР по листингам за период».
 *
 * Возвращает суммарные рекламные затраты с разрезом по `listing_id`, агрегированные
 * из `marketplace_ad_document_lines` за указанный период. Семантически соответствует
 * CTE `ads_agg` из {@see AdEfficiencyQuery}, вынесенному в отдельный read-only query
 * для переиспользования из других модулей через {@see \App\MarketplaceAds\Facade\MarketplaceAdsFacade}.
 *
 * В отличие от AdEfficiencyQuery здесь намеренно НЕТ inner-join на `marketplace_listings`:
 * результат включает «висячие» listing_id (строки в ad_document_lines, у которых нет
 * живого листинга) — это критично для согласованности с totals на потребителях, где
 * сумма по разрезу должна сходиться с полной суммой за период.
 *
 * DBAL, не ORM. Денежные значения наружу — decimal-строки (bcmath-compatible).
 */
final readonly class AdSpendByListingQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * РР с разрезом по листингам за период.
     *
     * Только attributed РР: listingId, упомянутые в marketplace_ad_document_lines
     * за период. Листинги без записей в результат не попадают — caller сам
     * решает дефолт ($map[$id] ?? '0').
     *
     * Для полной суммы за период (включая non-attributed) использовать
     * MarketplaceAdsFacade::getTotalAdCostForPeriod().
     *
     * @return array<string, string>  listingId => decimal-string adSpend
     */
    public function getByListingForPeriod(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $marketplace = null,
    ): array {
        $params = [
            'companyId' => $companyId,
            'periodFrom' => $from->format('Y-m-d'),
            'periodTo' => $to->format('Y-m-d'),
        ];

        $mpFilter = '';
        if (null !== $marketplace) {
            $mpFilter = 'AND ad.marketplace = :marketplace';
            $params['marketplace'] = $marketplace;
        }

        $sql = <<<SQL
            SELECT adl.listing_id, SUM(adl.cost) AS ad_spend
            FROM marketplace_ad_document_lines adl
            JOIN marketplace_ad_documents ad ON ad.id = adl.ad_document_id
            WHERE ad.company_id = :companyId
              AND ad.report_date BETWEEN :periodFrom AND :periodTo
              {$mpFilter}
            GROUP BY adl.listing_id
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['listing_id']] = (string) $row['ad_spend'];
        }

        return $result;
    }
}
