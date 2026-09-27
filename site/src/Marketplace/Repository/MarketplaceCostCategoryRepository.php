<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

class MarketplaceCostCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceCostCategory::class);
    }

    /**
     * @return MarketplaceCostCategory[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.isActive = :active')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndCompanyId(string $companyId, string $id): ?MarketplaceCostCategory
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->where('IDENTITY(c.company) = :companyId')
            ->andWhere('c.id = :id')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
