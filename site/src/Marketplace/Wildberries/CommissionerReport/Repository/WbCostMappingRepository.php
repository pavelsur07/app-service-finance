<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostMapping;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbDimensionValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WbCostMapping>
 */
final class WbCostMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WbCostMapping::class);
    }

    /**
     * @param list<WbDimensionValue> $dimensionValues
     *
     * @return list<WbCostMapping>
     */
    public function findByDimensionValues(Company $company, array $dimensionValues): array
    {
        if ([] === $dimensionValues) {
            return [];
        }

        return $this->createQueryBuilder('mapping')
            ->andWhere('mapping.company = :company')
            ->andWhere('mapping.dimensionValue IN (:dimensionValues)')
            ->setParameter('company', $company)
            ->setParameter('dimensionValues', $dimensionValues)
            ->getQuery()
            ->getResult();
    }
}
