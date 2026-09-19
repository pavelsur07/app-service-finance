<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\ReconciliationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReconciliationLog>
 */
class ReconciliationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconciliationLog::class);
    }

    public function save(ReconciliationLog $log): void
    {
        $this->getEntityManager()->persist($log);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
