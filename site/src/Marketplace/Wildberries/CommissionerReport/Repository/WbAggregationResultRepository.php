<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbAggregationResult;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WbAggregationResult>
 */
final class WbAggregationResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WbAggregationResult::class);
    }

    public function deleteByReport(Company $company, WildberriesCommissionerXlsxReport $report): void
    {
        $this->createQueryBuilder('result')
            ->delete()
            ->andWhere('result.company = :company')
            ->andWhere('result.report = :report')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->getQuery()
            ->execute();
    }
}
