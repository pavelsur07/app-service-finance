<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Marketplace\Wildberries\CommissionerReport\Entity\WbCommissionerReportRowRaw;
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
}
