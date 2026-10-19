<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\OzonAccrualTypeGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OzonAccrualTypeGroup>
 */
final class OzonAccrualTypeGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonAccrualTypeGroup::class);
    }

    /**
     * @companyScopeExempt группа типа начисления Ozon одна для всех продавцов, выборка не по данным компании
     *
     * @param bool $refresh перечитать уже загруженные сущности (вызов под блокировкой)
     *
     * @return array<int, OzonAccrualTypeGroup> type_id => группа
     */
    public function findAllIndexedByTypeId(bool $refresh = false): array
    {
        $query = $this->createQueryBuilder('type_group')
            ->orderBy('type_group.typeId', \SortDirection::Ascending)
            ->getQuery();
        // Под advisory-блокировкой уже загруженные сущности перечитываются из БД:
        // иначе запись другого воркера затёрлась бы кэшем UnitOfWork. Hint ставится
        // только при true — гидратор проверяет его через isset().
        if ($refresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }
        /** @var list<OzonAccrualTypeGroup> $groups */
        $groups = $query->getResult();

        $indexed = [];
        foreach ($groups as $group) {
            $indexed[$group->getTypeId()] = $group;
        }

        return $indexed;
    }
}
