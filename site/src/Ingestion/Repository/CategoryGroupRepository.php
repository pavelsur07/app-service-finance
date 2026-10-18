<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\CategoryGroup;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryGroup>
 */
final class CategoryGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryGroup::class);
    }

    /**
     * @companyScopeExempt закрытый список групп источника общий для всех компаний, companyId у сущности нет
     *
     * @return array<string, CategoryGroup> code => группа, в порядке sort_order
     */
    public function findBySourceIndexedByCode(IngestSource $source): array
    {
        /** @var list<CategoryGroup> $groups */
        $groups = $this->createQueryBuilder('category_group')
            ->andWhere('category_group.source = :source')
            ->setParameter('source', $source->value)
            ->orderBy('category_group.sortOrder', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($groups as $group) {
            $indexed[$group->getCode()] = $group;
        }

        return $indexed;
    }
}
