<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\Entity\PLTemplateApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<PLTemplateApplication>
 */
final class PLTemplateApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLTemplateApplication::class);
    }

    /**
     * Последнее применение шаблона — происхождение дерева ОПиУ компании; null — не записано.
     * Последнее, а не первое: дерево, удалённое и собранное заново шаблоном другого профиля,
     * принадлежит новому шаблону (к непустому дереву шаблон другого профиля не применяется,
     * поэтому иначе последняя запись с первой не расходится).
     */
    public function findLatestByCompanyId(string $companyId): ?PLTemplateApplication
    {
        Assert::uuid($companyId);

        $latest = $this->createQueryBuilder('a')
            ->andWhere('a.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('a.appliedAt', \SortDirection::Descending)
            ->addOrderBy('a.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latest instanceof PLTemplateApplication ? $latest : null;
    }
}
