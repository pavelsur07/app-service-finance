<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\NormalizationIssue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NormalizationIssue>
 */
final class NormalizationIssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NormalizationIssue::class);
    }

    /**
     * @return list<NormalizationIssue>
     */
    public function findOpenByRawRecord(string $companyId, string $rawRecordId): array
    {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.companyId = :companyId')
            ->andWhere('issue.rawRecordId = :rawRecordId')
            ->andWhere('issue.resolvedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->orderBy('issue.createdAt', 'ASC')
            ->addOrderBy('issue.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenForCompany(string $companyId): int
    {
        return (int) $this->createQueryBuilder('issue')
            ->select('COUNT(issue.id)')
            ->andWhere('issue.companyId = :companyId')
            ->andWhere('issue.resolvedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
