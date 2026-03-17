<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceCostPLMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceCostPLMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceCostPLMapping::class);
    }

    /**
     * @return MarketplaceCostPLMapping[]
     */
    public function findByCompany(string $companyId): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.costCategory', 'cc')
            ->addSelect('cc')
            ->where('m.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('cc.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCostCategory(
        string $companyId,
        string $costCategoryId,
    ): ?MarketplaceCostPLMapping {
        return $this->findOneBy([
            'companyId'    => $companyId,
            'costCategory' => $costCategoryId,
        ]);
    }

    public function save(MarketplaceCostPLMapping $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }
}
