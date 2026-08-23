<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Domain\Service\SnapshotCalculationPolicy;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RecalculateListingSnapshotsAction
{
    private const BATCH_SIZE = 25;

    public function __construct(
        private SnapshotCalculationPolicy $snapshotCalculationPolicy,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $listingIds
     */
    public function __invoke(
        string $companyId,
        array $listingIds,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $marketplace,
    ): void {
        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('Snapshot dateFrom must not be later than dateTo.');
        }

        $unflushedListingCount = 0;
        foreach (array_values(array_unique($listingIds)) as $listingId) {
            $date = $dateFrom;
            while ($date <= $dateTo) {
                $this->snapshotCalculationPolicy->calculateForListingDay(
                    $companyId,
                    $listingId,
                    $date,
                    $marketplace,
                );
                $date = $date->modify('+1 day');
            }

            ++$unflushedListingCount;
            if (self::BATCH_SIZE === $unflushedListingCount) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $unflushedListingCount = 0;
            }
        }

        if ($unflushedListingCount > 0) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }
}
