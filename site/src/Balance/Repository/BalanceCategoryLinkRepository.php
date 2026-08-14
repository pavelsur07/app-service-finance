<?php

declare(strict_types=1);

namespace App\Balance\Repository;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BalanceCategoryLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCategoryLink::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?BalanceCategoryLink
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    /**
     * @return BalanceCategoryLink[]
     */
    public function findByCompany(string $companyId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BalanceCategoryLink[]
     */
    public function findByCompanyAndCategory(string $companyId, BalanceCategory $category): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.companyId = :companyId')
            ->andWhere('l.category = :category')
            ->setParameter('companyId', $companyId)
            ->setParameter('category', $category)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
