<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalCategory>
 */
final class ExternalCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalCategory::class);
    }

    public function findByIdentity(
        IngestSource $source,
        string $resourceType,
        string $scope,
        string $normalizedKey,
    ): ?ExternalCategory {
        return $this->createQueryBuilder('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.scope = :scope')
            ->andWhere('category.normalizedKey = :normalizedKey')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('scope', $scope)
            ->setParameter('normalizedKey', $normalizedKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ExternalCategory>
     */
    public function findBySourceResourceAndNormalizedKey(
        IngestSource $source,
        string $resourceType,
        string $normalizedKey,
    ): array {
        return $this->createQueryBuilder('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.normalizedKey = :normalizedKey')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('normalizedKey', $normalizedKey)
            ->orderBy('category.scope', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ExternalCategory>
     */
    public function findByStatus(ExternalCategoryStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('category')
            ->andWhere('category.status = :status')
            ->setParameter('status', $status->value)
            ->orderBy('category.lastSeenAt', 'DESC')
            ->addOrderBy('category.createdAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
