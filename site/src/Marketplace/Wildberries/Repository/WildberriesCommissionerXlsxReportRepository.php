<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Repository;

use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WildberriesCommissionerXlsxReport>
 */
final class WildberriesCommissionerXlsxReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesCommissionerXlsxReport::class);
    }
}
