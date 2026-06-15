<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestionTenantProbe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<IngestionTenantProbe>
 */
final class IngestionTenantProbeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestionTenantProbe::class);
    }

    /**
     * @return list<IngestionTenantProbe>
     */
    public function findByCompanyId(string $companyId): array
    {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('probe')
            ->andWhere('probe.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('probe.createdAt', 'ASC')
            ->addOrderBy('probe.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
