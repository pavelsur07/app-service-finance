<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
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
     * Категории типов Ozon, которые ещё не подтверждены: в очереди разбора
     * (NEW, NEEDS_IDENTIFICATION) либо MAPPED без действующего маппинга с
     * known = true — refresh такую категорию известной не сделает. Сопоставление
     * категории с типом — у вызывающего кода.
     *
     * @companyScopeExempt внешние категории источника глобальные (см. ARCHITECTURE «canonical finance layer»), companyId у сущности нет
     *
     * @param list<string> $externalTypeIds
     * @param list<string> $normalizedKeys
     *
     * @return list<ExternalCategory>
     */
    public function findUnconfirmedByTypeIdsOrKeys(
        IngestSource $source,
        string $resourceType,
        array $externalTypeIds,
        array $normalizedKeys,
    ): array {
        if ([] === $externalTypeIds && [] === $normalizedKeys) {
            return [];
        }

        $confirmedMapping = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(ExternalCategoryMapping::class, 'mapping')
            ->andWhere('mapping.externalCategory = category')
            ->andWhere('mapping.status = :activeMapping')
            ->andWhere('mapping.known = true')
            ->getDQL();

        return $this->createQueryBuilder('category')
            ->andWhere('category.source = :source')
            ->andWhere('category.resourceType = :resourceType')
            ->andWhere('category.externalTypeId IN (:externalTypeIds) OR category.normalizedKey IN (:normalizedKeys)')
            ->andWhere(sprintf('category.status IN (:pending) OR (category.status = :mapped AND NOT EXISTS (%s))', $confirmedMapping))
            ->setParameter('source', $source->value)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalTypeIds', [] !== $externalTypeIds ? $externalTypeIds : ['-'])
            ->setParameter('normalizedKeys', [] !== $normalizedKeys ? $normalizedKeys : ['-'])
            ->setParameter('pending', [ExternalCategoryStatus::NEW->value, ExternalCategoryStatus::NEEDS_IDENTIFICATION->value])
            ->setParameter('mapped', ExternalCategoryStatus::MAPPED->value)
            ->setParameter('activeMapping', ExternalCategoryMappingStatus::ACTIVE->value)
            ->orderBy('category.scope', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
