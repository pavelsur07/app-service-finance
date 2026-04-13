<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\MarketplaceAds\Application\DTO\AdCostForListingDTO;
use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для чтения обработанных рекламных данных.
 * Используется исключительно {@see \App\MarketplaceAds\Facade\MarketplaceAdsFacade}.
 *
 * Работает напрямую с DBAL (без ORM hydration) — таблицы только читаются,
 * никаких изменений здесь не происходит.
 */
final readonly class AdDocumentQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Возвращает распределённые рекламные затраты по листингу за одну дату.
     *
     * Каждая строка — одна кампания (AdDocument), которая была распределена
     * на указанный листинг (AdDocumentLine).
     *
     * @return AdCostForListingDTO[]
     */
    public function findCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'd.id          AS ad_document_id',
                'd.campaign_id',
                'd.campaign_name',
                'l.cost',
                'l.impressions',
                'l.clicks',
                'l.share_percent',
            )
            ->from('marketplace_ad_document_lines', 'l')
            ->innerJoin('l', 'marketplace_ad_documents', 'd', 'd.id = l.ad_document_id')
            ->where('d.company_id = :companyId')
            ->andWhere('l.listing_id = :listingId')
            ->andWhere('d.report_date = :date')
            ->setParameter('companyId', $companyId)
            ->setParameter('listingId', $listingId)
            ->setParameter('date', $date->format('Y-m-d'))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): AdCostForListingDTO => new AdCostForListingDTO(
                adDocumentId: $row['ad_document_id'],
                campaignId: $row['campaign_id'],
                campaignName: $row['campaign_name'],
                cost: $row['cost'],
                impressions: (int) $row['impressions'],
                clicks: (int) $row['clicks'],
                sharePercent: $row['share_percent'],
            ),
            $rows,
        );
    }

    /**
     * Суммарные рекламные затраты компании за период.
     *
     * Суммирует `total_cost` из `marketplace_ad_documents` — это полные затраты
     * кампании до распределения по листингам. Сумма эквивалентна сумме
     * `cost` из `marketplace_ad_document_lines` (поскольку distributor
     * сохраняет итог с поправкой округления).
     *
     * @return string decimal-строка, например "1234.56"; "0" если данных нет.
     */
    public function sumTotalCostForPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $marketplace = null,
    ): string {
        $qb = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(d.total_cost), 0)')
            ->from('marketplace_ad_documents', 'd')
            ->where('d.company_id = :companyId')
            ->andWhere('d.report_date BETWEEN :dateFrom AND :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('dateFrom', $dateFrom->format('Y-m-d'))
            ->setParameter('dateTo', $dateTo->format('Y-m-d'));

        if (null !== $marketplace) {
            $qb->andWhere('d.marketplace = :marketplace')
               ->setParameter('marketplace', $marketplace);
        }

        return (string) $qb->executeQuery()->fetchOne();
    }
}
