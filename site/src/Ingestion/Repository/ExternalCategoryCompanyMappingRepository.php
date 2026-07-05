<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryCompanyMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalCategoryCompanyMapping>
 */
final class ExternalCategoryCompanyMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalCategoryCompanyMapping::class);
    }

    public function findByCategoryAndCompany(ExternalCategory $category, string $companyId): ?ExternalCategoryCompanyMapping
    {
        return $this->createQueryBuilder('mapping')
            ->andWhere('mapping.externalCategory = :category')
            ->andWhere('mapping.companyId = :companyId')
            ->setParameter('category', $category)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ExternalCategoryCompanyMapping>
     */
    public function findActiveBySourceResourceAndCompany(
        IngestSource $source,
        string $resourceType,
        string $companyId,
    ): array {
        return $this->createQueryBuilder('mapping')
            ->innerJoin('mapping.externalCategory', 'category')
            ->addSelect('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('mapping.companyId = :companyId')
            ->andWhere('mapping.status = :status')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('companyId', $companyId)
            ->setParameter('status', ExternalCategoryMappingStatus::ACTIVE->value)
            ->getQuery()
            ->getResult();
    }
}
