<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceRawProcessingStepRun;
use App\Marketplace\Enum\PipelineStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceRawProcessingStepRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawProcessingStepRun::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?MarketplaceRawProcessingStepRun
    {
        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    /**
     * @return list<MarketplaceRawProcessingStepRun>
     */
    public function findByRunId(string $companyId, string $processingRunId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.processingRunId = :processingRunId')
            ->setParameter('companyId', $companyId)
            ->setParameter('processingRunId', $processingRunId)
            ->orderBy('s.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByRunIdAndStep(
        string $companyId,
        string $processingRunId,
        PipelineStep $step,
    ): ?MarketplaceRawProcessingStepRun {
        return $this->findOneBy([
            'companyId' => $companyId,
            'processingRunId' => $processingRunId,
            'step' => $step,
        ]);
    }
}
