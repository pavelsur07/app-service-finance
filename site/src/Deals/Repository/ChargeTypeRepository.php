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

    public function findOneByIdForCompany(string $id, Company $company): ?ChargeType
    {
        return $this->createQueryBuilder('type')
            ->andWhere('type.id = :id')
            ->andWhere('type.company = :company')
            ->setParameter('id', $id)
            ->setParameter('company', $company)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCompanyAndCode(Company $company, string $code, ?string $excludeId = null): ?ChargeType
    {
        $queryBuilder = $this->createQueryBuilder('type')
            ->andWhere('type.company = :company')
            ->andWhere('type.code = :code')
            ->setParameter('company', $company)
            ->setParameter('code', $code);

        if (null !== $excludeId) {
            $queryBuilder
                ->andWhere('type.id <> :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Pagerfanta<ChargeType>
     */
    public function findListForCompany(Company $company, ?bool $isActive, int $page, int $limit): Pagerfanta
    {
        $queryBuilder = $this->createQueryBuilder('type')
            ->andWhere('type.company = :company')
            ->orderBy('type.name', 'ASC')
            ->setParameter('company', $company);

        if (null !== $isActive) {
            $queryBuilder
                ->andWhere('type.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
    }
}
