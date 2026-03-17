<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceMonthCloseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceMonthClose::class);
    }

    public function findByPeriod(
        string $companyId,
        MarketplaceType $marketplace,
        int $year,
        int $month,
    ): ?MarketplaceMonthClose {
        return $this->findOneBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
        ]);
    }

    /**
     * Найти или создать запись закрытия — возвращает существующую или null.
     * Создание происходит в Action через persist.
     *
     * @return MarketplaceMonthClose[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): array {
        return $this->createQueryBuilder('mc')
            ->where('mc.companyId = :companyId')
            ->andWhere('mc.marketplace = :marketplace')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->orderBy('mc.year', 'DESC')
            ->addOrderBy('mc.month', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(MarketplaceMonthClose $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }
}
