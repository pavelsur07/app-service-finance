<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceAdvertisingCost;
use App\Marketplace\Enum\AdvertisingType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceAdvertisingCostRepository extends ServiceEntityRepository implements MarketplaceAdvertisingCostRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceAdvertisingCost::class);
    }

    public function save(MarketplaceAdvertisingCost $cost): void
    {
        $this->getEntityManager()->persist($cost);
    }

    /**
     * @return MarketplaceAdvertisingCost[]
     */
    public function findByListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        return $this->findBy([
            'companyId' => $companyId,
            'listingId' => $listingId,
            'date' => $date,
        ]);
    }

    /**
     * @return MarketplaceAdvertisingCost[]
     */
    public function findByCompanyAndDate(
        string $companyId,
        \DateTimeImmutable $date,
    ): array {
        return $this->findBy([
            'companyId' => $companyId,
            'date' => $date,
        ]);
    }

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
        AdvertisingType $type,
        string $campaignId,
    ): ?MarketplaceAdvertisingCost {
        return $this->findOneBy([
            'companyId' => $companyId,
            'listingId' => $listingId,
            'date' => $date,
            'advertisingType' => $type,
            'externalCampaignId' => $campaignId,
        ]);
    }
}
