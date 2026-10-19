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
            ->orderBy('category.scope', \SortDirection::Ascending)
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
            ->orderBy('category.lastSeenAt', \SortDirection::Descending)
            ->addOrderBy('category.createdAt', \SortDirection::Descending)
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @companyScopeExempt внешние категории источника глобальные (см. ARCHITECTURE «canonical finance layer»), companyId у сущности нет
     *
     * @param list<string> $normalizedKeys
     * @param list<ExternalCategoryStatus> $statuses
     *
     * @return list<ExternalCategory>
     */
    public function findByTypeIdOrKeys(
        IngestSource $source,
        string $resourceType,
        string $externalTypeId,
        array $normalizedKeys,
        array $statuses,
    ): array {
        return $this->createQueryBuilder('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.externalTypeId = :externalTypeId OR category.normalizedKey IN (:normalizedKeys)')
            ->andWhere('category.status IN (:statuses)')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalTypeId', $externalTypeId)
            ->setParameter('normalizedKeys', [] !== $normalizedKeys ? $normalizedKeys : ['-'])
            ->setParameter('statuses', array_map(static fn (ExternalCategoryStatus $status): string => $status->value, $statuses))
            ->orderBy('category.scope', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Пакетный вариант findByTypeIdOrKeys: категории для нескольких типов одним
     * запросом, сопоставление с типом — у вызывающего кода.
     *
     * @companyScopeExempt внешние категории источника глобальные (см. ARCHITECTURE «canonical finance layer»), companyId у сущности нет
     *
     * @param list<string> $externalTypeIds
     * @param list<string> $normalizedKeys
     * @param list<ExternalCategoryStatus> $statuses
     *
     * @return list<ExternalCategory>
     */
    public function findByTypeIdsOrKeys(
        IngestSource $source,
        string $resourceType,
        array $externalTypeIds,
        array $normalizedKeys,
        array $statuses,
    ): array {
        if ([] === $externalTypeIds && [] === $normalizedKeys) {
            return [];
        }

        return $this->createQueryBuilder('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.externalTypeId IN (:externalTypeIds) OR category.normalizedKey IN (:normalizedKeys)')
            ->andWhere('category.status IN (:statuses)')
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalTypeIds', [] !== $externalTypeIds ? $externalTypeIds : ['-'])
            ->setParameter('normalizedKeys', [] !== $normalizedKeys ? $normalizedKeys : ['-'])
            ->setParameter('statuses', array_map(static fn (ExternalCategoryStatus $status): string => $status->value, $statuses))
            ->getQuery()
            ->getResult();
    }
}
