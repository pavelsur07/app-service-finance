<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceRawProcessingRun;
use App\Marketplace\Enum\PipelineStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceRawProcessingRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawProcessingRun::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?MarketplaceRawProcessingRun
    {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    public function findLatestByRawDocument(string $companyId, string $rawDocumentId): ?MarketplaceRawProcessingRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.rawDocumentId = :rawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<MarketplaceRawProcessingRun>
     */
    public function findActiveRuns(string $companyId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', [
                PipelineStatus::PENDING,
                PipelineStatus::RUNNING,
            ])
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
