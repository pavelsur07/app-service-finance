<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Infrastructure\Repository;

use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ORM-репозиторий для WRITE операций субмодуля Inventory.
 * READ для UI — через DBAL InventoryCostListingQuery.
 */
class MarketplaceInventoryCostPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceInventoryCostPrice::class);
    }

    /**
     * Найти активную запись для листинга на дату.
     * Используется из InventoryCostPriceResolver.
     */
    public function findActiveAtDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $at,
    ): ?MarketplaceInventoryCostPrice {
        return $this->createQueryBuilder('p')
            ->where('p.companyId = :companyId')
            ->andWhere('IDENTITY(p.listing) = :listingId')
            ->andWhere('p.effectiveFrom <= :at')
            ->andWhere('(p.effectiveTo IS NULL OR p.effectiveTo >= :at)')
            ->setParameter('companyId', $companyId)
            ->setParameter('listingId', $listingId)
            ->setParameter('at', $at)
            ->orderBy('p.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Найти последнюю запись для листинга (без ограничения по дате).
     * Используется в SetInventoryCostPriceAction для закрытия предыдущего периода.
     */
    public function findLatest(
        string $companyId,
        string $listingId,
    ): ?MarketplaceInventoryCostPrice {
        return $this->createQueryBuilder('p')
            ->where('p.companyId = :companyId')
            ->andWhere('IDENTITY(p.listing) = :listingId')
            ->setParameter('companyId', $companyId)
            ->setParameter('listingId', $listingId)
            ->orderBy('p.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Найти следующую запись после даты.
     * Используется в SetInventoryCostPriceAction для проверки перекрытий.
     */
    public function findNextAfterDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $from,
    ): ?MarketplaceInventoryCostPrice {
        return $this->createQueryBuilder('p')
            ->where('p.companyId = :companyId')
            ->andWhere('IDENTITY(p.listing) = :listingId')
            ->andWhere('p.effectiveFrom > :from')
            ->setParameter('companyId', $companyId)
            ->setParameter('listingId', $listingId)
            ->setParameter('from', $from)
            ->orderBy('p.effectiveFrom', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
