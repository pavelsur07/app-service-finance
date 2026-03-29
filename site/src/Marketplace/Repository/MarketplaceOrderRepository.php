<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceOrder;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceOrderRepository extends ServiceEntityRepository implements MarketplaceOrderRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceOrder::class);
    }

    public function save(MarketplaceOrder $order): void
    {
        $this->getEntityManager()->persist($order);
    }

    /**
     * @return MarketplaceOrder[]
     */
    public function findByListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array {
        return $this->findBy([
            'companyId' => $companyId,
            'listingId' => $listingId,
            'orderDate' => $date,
        ]);
    }

    /**
     * @return MarketplaceOrder[]
     */
    public function findByCompanyAndDate(
        string $companyId,
        \DateTimeImmutable $date,
    ): array {
        return $this->findBy([
            'companyId' => $companyId,
            'orderDate' => $date,
        ]);
    }

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?MarketplaceOrder {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findByExternalId(
        string $companyId,
        MarketplaceType $marketplace,
        string $externalOrderId,
    ): ?MarketplaceOrder {
        return $this->findOneBy([
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'externalOrderId' => $externalOrderId,
        ]);
    }
}
