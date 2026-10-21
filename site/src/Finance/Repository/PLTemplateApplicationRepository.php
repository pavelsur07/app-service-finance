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
     * Первое применение шаблона — происхождение дерева ОПиУ компании; null — не записано.
     */
    public function findFirstByCompanyId(string $companyId): ?PLTemplateApplication
    {
        Assert::uuid($companyId);

        $first = $this->createQueryBuilder('a')
            ->andWhere('a.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('a.appliedAt', \SortDirection::Ascending)
            ->addOrderBy('a.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $first instanceof PLTemplateApplication ? $first : null;
    }
}
