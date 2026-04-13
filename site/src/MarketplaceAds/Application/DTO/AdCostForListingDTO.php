<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

/**
 * Рекламные затраты, распределённые на конкретный листинг за одну дату.
 *
 * Возвращается из {@see \App\MarketplaceAds\Facade\MarketplaceAdsFacade::getAdCostsForListingAndDate()}.
 * Каждый экземпляр соответствует одной кампании (AdDocument), которая
 * была распределена на запрошенный листинг (AdDocumentLine).
 */
final readonly class AdCostForListingDTO
{
    public function __construct(
        /** ID AdDocument — кампании за дату */
        public string $adDocumentId,
        /** Идентификатор кампании на маркетплейсе */
        public string $campaignId,
        /** Название кампании на маркетплейсе */
        public string $campaignName,
        /** Распределённые затраты (decimal-строка, например "12.50") */
        public string $cost,
        /** Распределённые показы */
        public int $impressions,
        /** Распределённые клики */
        public int $clicks,
        /** Доля распределения 0–100 (decimal-строка, например "33.3333") */
        public string $sharePercent,
    ) {
    }
}
