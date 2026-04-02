<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\ProcessingPipelineRun;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ProcessingPipelineRunRepository extends ServiceEntityRepository implements ProcessingPipelineRunRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessingPipelineRun::class);
    }

    public function save(ProcessingPipelineRun $run): void
    {
        $this->getEntityManager()->persist($run);
    }

    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): ?ProcessingPipelineRun {
        return $this->findOneBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
        ]);
    }

    /**
     * @return ProcessingPipelineRun[]
     */
    public function findAllForCompany(string $companyId): array
    {
        return $this->findBy(['companyId' => $companyId]);
    }
}
