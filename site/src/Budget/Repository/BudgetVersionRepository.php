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
}
