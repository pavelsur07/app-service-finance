<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\OzonAccrualType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OzonAccrualType>
 */
final class OzonAccrualTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonAccrualType::class);
    }

    /**
     * @companyScopeExempt справочник Ozon общий для всех продавцов, у сущности нет companyId
     *
     * @param bool $refresh перечитать уже загруженные сущности (вызов под блокировкой)
     *
     * @return array<int, OzonAccrualType> type_id => тип
     */
    public function findAllIndexedByTypeId(bool $refresh = false): array
    {
        $query = $this->createQueryBuilder('type')
            ->orderBy('type.typeId', \SortDirection::Ascending)
            ->getQuery();
        // Под advisory-блокировкой уже загруженные сущности перечитываются из БД:
        // иначе запись другого воркера затёрлась бы кэшем UnitOfWork. Hint ставится
        // только при true — гидратор проверяет его через isset().
        if ($refresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }
        /** @var list<OzonAccrualType> $types */
        $types = $query->getResult();

        $indexed = [];
        foreach ($types as $type) {
            $indexed[$type->getTypeId()] = $type;
        }

        return $indexed;
    }

    /**
     * @companyScopeExempt справочник Ozon общий для всех продавцов, у сущности нет companyId
     */
    public function findLatestVersionFetchedAt(): ?\DateTimeImmutable
    {
        /** @var string|null $value */
        $value = $this->createQueryBuilder('type')
            ->select('MAX(type.versionFetchedAt)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : new \DateTimeImmutable($value);
    }
}
