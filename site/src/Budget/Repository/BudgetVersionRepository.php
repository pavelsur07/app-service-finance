<?php

declare(strict_types=1);

namespace App\Budget\Repository;

use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BudgetVersion>
 */
final class BudgetVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetVersion::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?BudgetVersion
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    public function findApprovedByYear(string $companyId, int $year): ?BudgetVersion
    {
        return $this->findOneBy(['companyId' => $companyId, 'year' => $year, 'status' => BudgetVersionStatus::APPROVED]);
    }

    /**
     * Варианты года: утверждённый первым, затем по дате изменения.
     *
     * @return list<BudgetVersion>
     */
    public function findByCompanyIdAndYear(string $companyId, int $year): array
    {
        /** @var list<BudgetVersion> $versions */
        $versions = $this->createQueryBuilder('v')
            ->andWhere('v.companyId = :companyId')
            ->andWhere('v.year = :year')
            ->setParameter('companyId', $companyId)
            ->setParameter('year', $year)
            ->orderBy('v.updatedAt', \SortDirection::Descending)
            ->addOrderBy('v.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
        usort($versions, static fn (BudgetVersion $a, BudgetVersion $b): int => (int) (BudgetVersionStatus::APPROVED !== $a->getStatus()) <=> (int) (BudgetVersionStatus::APPROVED !== $b->getStatus()));

        return $versions;
    }

    /**
     * @return list<int>
     */
    public function findYearsByCompanyId(string $companyId): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT year FROM budget_versions WHERE company_id = :companyId ORDER BY year',
            ['companyId' => $companyId],
        ));
    }
}
