<?php

namespace App\Deals\Repository;

use App\Company\Entity\Company;
use App\Deals\Entity\ChargeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

/**
 * @extends ServiceEntityRepository<ChargeType>
 */
class ChargeTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChargeType::class);
    }

    /**
     * @return Pagerfanta<ChargeType>
     */
    public function findActiveByCompany(Company $company): Pagerfanta
    {
        $queryBuilder = $this->createQueryBuilder('type')
            ->andWhere('type.company = :company')
            ->andWhere('type.isActive = true')
            ->orderBy('type.name', 'ASC')
            ->setParameter('company', $company);

        return new Pagerfanta(new QueryAdapter($queryBuilder));
    }
}
