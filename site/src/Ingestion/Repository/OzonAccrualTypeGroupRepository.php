<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\OzonAccrualTypeGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @return array<int, OzonAccrualTypeGroup> type_id => группа
     */
    public function findAllIndexedByTypeId(): array
    {
        /** @var list<OzonAccrualTypeGroup> $groups */
        $groups = $this->createQueryBuilder('type_group')
            ->orderBy('type_group.typeId', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($groups as $group) {
            $indexed[$group->getTypeId()] = $group;
        }

        return $indexed;
    }
}
