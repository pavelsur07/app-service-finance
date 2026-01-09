<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Repository;

use App\Marketplace\Wildberries\Entity\WildberriesImportLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WildberriesImportLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesImportLog::class);
    }
}
