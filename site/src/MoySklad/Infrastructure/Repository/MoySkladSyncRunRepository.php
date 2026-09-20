<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladSyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladSyncRun> */
final class MoySkladSyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladSyncRun::class);
    }

    public function latestFor(string $companyId, string $connectionId, string $entityType): ?MoySkladSyncRun
    {
        return $this->findOneBy(
            ['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType],
            ['startedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function findByIdAndCompanyId(string $id, string $companyId): ?MoySkladSyncRun
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    public function runningFor(string $companyId, string $connectionId, string $entityType): ?MoySkladSyncRun
    {
        return $this->findOneBy(['companyId' => $companyId, 'connectionId' => $connectionId, 'entityType' => $entityType, 'status' => 'running']);
    }
}
