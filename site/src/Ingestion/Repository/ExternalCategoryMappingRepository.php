<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalCategoryMapping>
 */
final class ExternalCategoryMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalCategoryMapping::class);
    }

    public function findActiveByCategory(ExternalCategory $category): ?ExternalCategoryMapping
    {
        return $this->createQueryBuilder('mapping')
            ->andWhere('mapping.externalCategory = :category')
            ->andWhere('mapping.status = :status')
            ->setParameter('category', $category)
            ->setParameter('status', ExternalCategoryMappingStatus::ACTIVE->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCategory(ExternalCategory $category): ?ExternalCategoryMapping
    {
        return $this->createQueryBuilder('mapping')
            ->andWhere('mapping.externalCategory = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByIdentity(
        IngestSource $source,
        string $resourceType,
        string $scope,
        string $normalizedKey,
    ): ?ExternalCategoryMapping {
        return $this->createQueryBuilder('mapping')
            ->innerJoin('mapping.externalCategory', 'category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.scope = :scope')
            ->andWhere('category.normalizedKey = :normalizedKey')
            ->andWhere('mapping.status = :status')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('scope', $scope)
            ->setParameter('normalizedKey', $normalizedKey)
            ->setParameter('status', ExternalCategoryMappingStatus::ACTIVE->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ExternalCategoryMapping>
     */
    public function findActiveBySourceAndResource(IngestSource $source, string $resourceType): array
    {
        return $this->createQueryBuilder('mapping')
            ->innerJoin('mapping.externalCategory', 'category')
            ->addSelect('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('mapping.status = :status')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('status', ExternalCategoryMappingStatus::ACTIVE->value)
            ->getQuery()
            ->getResult();
    }
}
