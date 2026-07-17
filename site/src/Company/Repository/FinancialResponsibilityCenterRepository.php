<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FinancialResponsibilityCenter>
 */
final class FinancialResponsibilityCenterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinancialResponsibilityCenter::class);
    }

    /**
     * @return list<FinancialResponsibilityCenter>
     */
    public function findActiveByCompanyId(string $companyId): array
    {
        /** @var list<FinancialResponsibilityCenter> $result */
        $result = $this->createQueryBuilder('center')
            ->andWhere('center.companyId = :companyId')
            ->andWhere('center.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', FinancialResponsibilityCenterStatus::ACTIVE)
            ->orderBy('center.sort', 'ASC')
            ->addOrderBy('center.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?FinancialResponsibilityCenter
    {
        return $this->createQueryBuilder('center')
            ->andWhere('center.id = :id')
            ->andWhere('center.companyId = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findGeneralByCompanyId(string $companyId): ?FinancialResponsibilityCenter
    {
        return $this->createQueryBuilder('center')
            ->andWhere('center.companyId = :companyId')
            ->andWhere('center.code = :code')
            ->setParameter('companyId', $companyId)
            ->setParameter('code', FinancialResponsibilityCenter::CODE_GENERAL)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<FinancialResponsibilityCenter>
     */
    public function findForManagement(string $companyId, bool $includeArchived = false): array
    {
        $queryBuilder = $this->createQueryBuilder('center')
            ->andWhere('center.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('center.sort', 'ASC')
            ->addOrderBy('center.name', 'ASC');

        if (!$includeArchived) {
            $queryBuilder
                ->andWhere('center.status = :status')
                ->setParameter('status', FinancialResponsibilityCenterStatus::ACTIVE);
        }

        /** @var list<FinancialResponsibilityCenter> $centers */
        $centers = $queryBuilder->getQuery()->getResult();

        return $centers;
    }
}
