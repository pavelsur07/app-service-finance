<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCommissionerReportRowRaw;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WbCommissionerReportRowRaw>
 */
final class WbCommissionerReportRowRawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WbCommissionerReportRowRaw::class);
    }

    public function deleteByReport(Company $company, WildberriesCommissionerXlsxReport $report): void
    {
        $this->createQueryBuilder('row')
            ->delete()
            ->andWhere('row.company = :company')
            ->andWhere('row.report = :report')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->getQuery()
            ->execute();
    }
}
