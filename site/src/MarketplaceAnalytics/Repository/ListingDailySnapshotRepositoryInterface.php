<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

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
        ?string $marketplace = null,
    ): array;

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): ?ListingDailySnapshot;

    /**
     * @return array{items: ListingDailySnapshot[], total: int}
     */
    public function findPaginated(
        string $companyId,
        string $marketplace,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?string $listingId,
        int $page,
        int $perPage,
    ): array;

    public function findById(
        string $id,
        string $companyId,
    ): ?ListingDailySnapshot;
}
