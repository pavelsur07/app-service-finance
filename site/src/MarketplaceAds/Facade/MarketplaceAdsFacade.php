<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Facade;

use App\MarketplaceAds\Application\DTO\AdCostForListingDTO;
use App\MarketplaceAds\Infrastructure\Query\AdDocumentQueryInterface;
use App\MarketplaceAds\Infrastructure\Query\AdSpendByListingQuery;

/**
 * Публичный API модуля MarketplaceAds для других модулей.
 *
 * Единственная разрешённая точка входа: другие модули (MarketplaceAnalytics и др.)
 * ДОЛЖНЫ обращаться к данным рекламы только через этот Facade —
 * импортировать Repository, Query или Entity напрямую запрещено.
 *
 * Принимает и возвращает скаляры + DTO, никогда не Entity.
 */
final readonly class MarketplaceAdsFacade
{
    public function __construct(
        private AdDocumentQueryInterface $adDocumentQuery,
        private AdSpendByListingQuery $adSpendByListingQuery,
    ) {
    }

    /**
     * Рекламные затраты, распределённые на листинг за конкретную дату.
     *
     * Каждый элемент массива — одна кампания, часть затрат которой
     * была атрибутирована запрошенному листингу согласно соотношению продаж.
     *
     * @return AdCostForListingDTO[]
     */
    public function getAdCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        return $this->adDocumentQuery->findCostsForListingAndDate($companyId, $listingId, $date);
    }

    /**
     * Суммарные рекламные затраты компании за период.
     *
     * @param string|null $marketplace значение MarketplaceType::value ('wildberries', 'ozon').
     *                                 Если null — суммируются все площадки.
     *
     * @return string decimal-строка, например "4567.89"; "0" если данных нет.
     */
    public function getTotalAdCostForPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $marketplace = null,
    ): string {
        return $this->adDocumentQuery->sumTotalCostForPeriod($companyId, $dateFrom, $dateTo, $marketplace);
    }

    /**
     * РР с разрезом по листингам за период.
     *
     * Для построения строк отчётов с колонкой РР по листингу.
     * Для totals (полная сумма за период, включая non-attributed) использовать
     * {@see self::getTotalAdCostForPeriod()}.
     *
     * @param string|null $marketplace значение MarketplaceType::value ('wildberries', 'ozon').
     *                                 Если null — суммируются все площадки.
     *
     * @return array<string, string>  listingId => decimal-string adSpend
     */
    public function getAdSpendByListingForPeriod(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $marketplace = null,
    ): array {
        return $this->adSpendByListingQuery->getByListingForPeriod(
            $companyId,
            $from,
            $to,
            $marketplace,
        );
    }
}
