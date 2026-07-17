<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\FinancialResponsibilityCenterProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FinancialResponsibilityCenterProject>
 */
final class FinancialResponsibilityCenterProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinancialResponsibilityCenterProject::class);
    }

    public function isAllowed(
        string $companyId,
        string $projectDirectionId,
        string $responsibilityCenterId,
    ): bool {
        return null !== $this->createQueryBuilder('pair')
            ->select('pair.id')
            ->andWhere('pair.companyId = :companyId')
            ->andWhere('IDENTITY(pair.projectDirection) = :projectDirectionId')
            ->andWhere('IDENTITY(pair.responsibilityCenter) = :responsibilityCenterId')
            ->setParameter('companyId', $companyId)
            ->setParameter('projectDirectionId', $projectDirectionId)
            ->setParameter('responsibilityCenterId', $responsibilityCenterId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<string>
     */
    public function findProjectIds(string $companyId, string $responsibilityCenterId): array
    {
        /** @var list<array{projectId: string}> $rows */
        $rows = $this->createQueryBuilder('pair')
            ->select('IDENTITY(pair.projectDirection) AS projectId')
            ->andWhere('pair.companyId = :companyId')
            ->andWhere('IDENTITY(pair.responsibilityCenter) = :responsibilityCenterId')
            ->setParameter('companyId', $companyId)
            ->setParameter('responsibilityCenterId', $responsibilityCenterId)
            ->getQuery()
            ->getArrayResult();

        return \array_column($rows, 'projectId');
    }
}
