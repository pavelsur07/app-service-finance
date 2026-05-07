<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\MarketplaceAds\Application\DTO\AdCostForListingDTO;

interface AdDocumentQueryInterface
{
    /**
     * @return AdCostForListingDTO[]
     */
    public function findCostsForListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array;

    /**
     * @return string decimal-строка, например "1234.56"; "0" если данных нет.
     */
    public function sumTotalCostForPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $marketplace = null,
    ): string;
}
