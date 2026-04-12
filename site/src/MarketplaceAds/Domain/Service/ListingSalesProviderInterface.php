<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Domain\Service;

interface ListingSalesProviderInterface
{
    /**
     * Bulk-получение количества продаж для набора листингов за дату.
     * Листинги без продаж отсутствуют в результате (caller получает 0 по умолчанию).
     *
     * @param  string[]           $listingIds
     * @return array<string, int> listingId => количество продаж
     */
    public function getSalesQuantitiesByListings(
        string $companyId,
        array $listingIds,
        \DateTimeImmutable $date,
    ): array;

    /**
     * Получить все листинги (включая неактивные) по родительскому SKU и площадке.
     * Необходимо для корректной обработки исторических отчётов.
     *
     * @return list<array{id: string, parentSku: string}>
     */
    public function findListingsByParentSku(
        string $companyId,
        string $marketplace,
        string $parentSku,
    ): array;
}
