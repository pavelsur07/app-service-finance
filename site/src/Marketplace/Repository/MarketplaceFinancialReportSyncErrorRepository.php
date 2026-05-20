<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MarketplaceFinancialReportSyncErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceFinancialReportSyncError::class);
    }

    public function save(MarketplaceFinancialReportSyncError $syncError): void
    {
        $this->getEntityManager()->persist($syncError);
    }
}
