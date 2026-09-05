<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\Entity\DocumentOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentOperation::class);
    }

    /**
     * Компания достижима только через Document — у DocumentOperation своего
     * companyId нет, поэтому join обязателен, иначе выборка не была бы ограничена
     * компанией.
     */
    public function countByCategory(string $companyId, string $categoryId): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->innerJoin('o.document', 'd')
            ->andWhere('d.company = :companyId')
            ->andWhere('o.category = :categoryId')
            ->setParameter('companyId', $companyId)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
