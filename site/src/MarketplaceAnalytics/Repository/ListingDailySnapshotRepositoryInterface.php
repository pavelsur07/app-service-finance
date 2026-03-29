<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;

interface ListingDailySnapshotRepositoryInterface
{
    public function save(ListingDailySnapshot $snapshot): void;

    /**
     * @return ListingDailySnapshot[]
     */
    public function findByCompanyAndPeriod(
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?MarketplaceType $marketplace = null,
        ?string $listingId = null,
    ): array;

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $snapshotDate,
    ): ?ListingDailySnapshot;

    /**
     * @return array{items: ListingDailySnapshot[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?MarketplaceType $marketplace,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        int $page,
        int $perPage,
    ): array;

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?ListingDailySnapshot;
}
