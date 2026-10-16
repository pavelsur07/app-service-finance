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
            ->orderBy('center.sort', \SortDirection::Ascending)
            ->addOrderBy('center.name', \SortDirection::Ascending)
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

    /**
     * Архивные тоже: документ может ссылаться на ЦФО, который потом отправили в архив.
     *
     * @param list<string> $ids
     *
     * @return list<FinancialResponsibilityCenter>
     */
    public function findByIdsAndCompanyId(array $ids, string $companyId): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<FinancialResponsibilityCenter> $result */
        $result = $this->createQueryBuilder('center')
            ->andWhere('center.id IN (:ids)')
            ->andWhere('center.companyId = :companyId')
            ->setParameter('ids', $ids)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();

        return $result;
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
            ->orderBy('center.sort', \SortDirection::Ascending)
            ->addOrderBy('center.name', \SortDirection::Ascending);

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
