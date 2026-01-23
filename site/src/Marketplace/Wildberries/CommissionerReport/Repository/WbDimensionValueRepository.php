<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Entity\WbDimensionValue;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WbDimensionValue>
 */
final class WbDimensionValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WbDimensionValue::class);
    }

    public function deleteByReport(Company $company, WildberriesCommissionerXlsxReport $report): void
    {
        $this->createQueryBuilder('dimension')
            ->delete()
            ->andWhere('dimension.company = :company')
            ->andWhere('dimension.report = :report')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->getQuery()
            ->execute();
    }
}
