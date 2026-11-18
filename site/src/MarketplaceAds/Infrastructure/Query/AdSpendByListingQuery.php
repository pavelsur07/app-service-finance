<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\Marketplace\Enum\AdSpendKind;
use App\MarketplaceAds\Domain\ValueObject\OzonSearchPromoCampaign;
use Doctrine\DBAL\ArrayParameterType;
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
     * @param list<string>|null $listingIds
     *
     * @return array{linked: float, estimated: float}
     */
    public function getOzonPayPerOrderAttribution(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $listingIds,
    ): array {
        if ([] === $listingIds) {
            return ['linked' => 0.0, 'estimated' => 0.0];
        }

        $campaignFilter = OzonSearchPromoCampaign::campaignCondition('ad.campaign_id', AdSpendKind::PAY_PER_ORDER);
        $listingFilter = null === $listingIds ? '' : 'AND line.listing_id IN (:listingIds)';
        $row = $this->connection->fetchAssociative(
            <<<SQL
            SELECT COALESCE(SUM(line.cost), 0) AS linked,
                   COALESCE(SUM(line.cost) FILTER (WHERE ad.campaign_id = :estimated), 0) AS estimated
            FROM marketplace_ad_documents ad
            JOIN marketplace_ad_document_lines line ON line.ad_document_id = ad.id
            JOIN marketplace_listings listing ON listing.id = line.listing_id
                AND listing.company_id = ad.company_id AND listing.marketplace = ad.marketplace
            WHERE ad.company_id = :company AND ad.marketplace = 'ozon'
                AND ad.report_date BETWEEN :dateFrom AND :dateTo AND {$campaignFilter}
                {$listingFilter}
            SQL,
            [
                'company' => $companyId,
                'dateFrom' => $from->format('Y-m-d'),
                'dateTo' => $to->format('Y-m-d'),
                'estimated' => OzonSearchPromoCampaign::ALL_SKU_CAMPAIGN_ID,
            ] + (null === $listingIds ? [] : ['listingIds' => $listingIds]),
            null === $listingIds ? [] : ['listingIds' => ArrayParameterType::STRING],
        );

        return ['linked' => (float) ($row['linked'] ?? 0), 'estimated' => (float) ($row['estimated'] ?? 0)];
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
     * @return array<string, string> listingId => decimal-string adSpend
     */
    public function getByListingForPeriod(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $marketplace = null,
        ?AdSpendKind $kind = null,
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
        $kindCondition = OzonSearchPromoCampaign::campaignCondition('ad.campaign_id', $kind);
        if (null !== $kindCondition) {
            $mpFilter .= ' AND '.$kindCondition;
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
