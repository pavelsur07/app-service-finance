<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\CategoryGroupRule;
use App\Ingestion\Enum\IngestSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategoryGroupRule>
 */
final class CategoryGroupRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategoryGroupRule::class);
    }

    /**
     * @companyScopeExempt правила подсказки групп общие для всех компаний, companyId у сущности нет
     *
     * @return list<CategoryGroupRule> по возрастанию priority
     */
    public function findActiveBySource(IngestSource $source): array
    {
        /** @var list<CategoryGroupRule> $rules */
        $rules = $this->createQueryBuilder('rule')
            ->andWhere('rule.source = :source')
            ->andWhere('rule.active = true')
            ->setParameter('source', $source->value)
            ->orderBy('rule.priority', \SortDirection::Ascending)
            ->addOrderBy('rule.createdAt', \SortDirection::Ascending)
            ->addOrderBy('rule.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $rules;
    }
}
