<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Query;

use App\Marketplace\Enum\AdSpendKind;
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
     * @param AdSpendKind|null $kind только этот вид расхода; null — весь
     *
     * @return string decimal-строка, например "1234.56"; "0" если данных нет.
     */
    public function sumTotalCostForPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $marketplace = null,
        ?AdSpendKind $kind = null,
    ): string;
}
