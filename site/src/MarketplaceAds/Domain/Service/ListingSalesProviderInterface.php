<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Domain\Service;

interface ListingSalesProviderInterface
{
    /**
     * Получить количество продаж листинга за дату.
     *
     * @return int количество проданных единиц
     */
    public function getSalesQuantityForDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): int;

    /**
     * Получить все листинги по родительскому SKU и площадке.
     *
     * @return list<array{id: string, parentSku: string}>
     */
    public function findListingsByParentSku(
        string $companyId,
        string $marketplace,
        string $parentSku,
    ): array;
}
