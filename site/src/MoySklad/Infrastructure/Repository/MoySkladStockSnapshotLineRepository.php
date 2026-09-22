<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Repository;

use App\MoySklad\Entity\MoySkladStockSnapshotLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MoySkladStockSnapshotLine> */
final class MoySkladStockSnapshotLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoySkladStockSnapshotLine::class);
    }

    public function countForSnapshot(string $companyId, string $snapshotId): int
    {
        return $this->count(['companyId' => $companyId, 'snapshotId' => $snapshotId]);
    }
}
