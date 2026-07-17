<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
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

    public function findGeneralByCompanyId(string $companyId): ?FinancialResponsibilityCenterProject
    {
        return $this->createQueryBuilder('pair')
            ->innerJoin('pair.projectDirection', 'project')
            ->innerJoin('pair.responsibilityCenter', 'center')
            ->andWhere('pair.companyId = :companyId')
            ->andWhere('project.systemCode = :projectCode')
            ->andWhere('center.code = :centerCode')
            ->andWhere('center.status = :centerStatus')
            ->setParameter('companyId', $companyId)
            ->setParameter('projectCode', ProjectDirection::CODE_GENERAL)
            ->setParameter('centerCode', FinancialResponsibilityCenter::CODE_GENERAL)
            ->setParameter('centerStatus', FinancialResponsibilityCenterStatus::ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<FinancialResponsibilityCenterProject>
     */
    public function findActiveByCompanyId(string $companyId): array
    {
        /** @var list<FinancialResponsibilityCenterProject> $pairs */
        $pairs = $this->createQueryBuilder('pair')
            ->addSelect('project', 'center')
            ->innerJoin('pair.projectDirection', 'project')
            ->innerJoin('pair.responsibilityCenter', 'center')
            ->andWhere('pair.companyId = :companyId')
            ->andWhere('IDENTITY(project.company) = :companyId')
            ->andWhere('center.companyId = :companyId')
            ->andWhere('center.status = :centerStatus')
            ->setParameter('companyId', $companyId)
            ->setParameter('centerStatus', FinancialResponsibilityCenterStatus::ACTIVE)
            ->getQuery()
            ->getResult();

        return $pairs;
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

    /**
     * @return list<FinancialResponsibilityCenterProject>
     */
    public function findByCenterIdAndCompanyId(string $responsibilityCenterId, string $companyId): array
    {
        /** @var list<FinancialResponsibilityCenterProject> $pairs */
        $pairs = $this->createQueryBuilder('pair')
            ->andWhere('IDENTITY(pair.responsibilityCenter) = :responsibilityCenterId')
            ->andWhere('pair.companyId = :companyId')
            ->setParameter('responsibilityCenterId', $responsibilityCenterId)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();

        return $pairs;
    }
}
