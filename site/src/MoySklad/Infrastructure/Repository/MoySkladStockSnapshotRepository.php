<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladStockSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladStockSnapshot> */
final class MoySkladStockSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladStockSnapshot::class);
    }

    public function findByIdAndCompanyId(string $id, string $companyId): ?MoySkladStockSnapshot
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    public function latestCompleted(string $companyId, string $connectionId): ?MoySkladStockSnapshot
    {
        return $this->findOneBy(
            ['companyId' => $companyId, 'connectionId' => $connectionId, 'status' => 'completed'],
            ['completedAt' => 'DESC', 'id' => 'DESC'],
        );
    }
}
