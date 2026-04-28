<?php

declare(strict_types=1);

namespace App\Inventory\Repository;

use App\Inventory\Entity\InventoryRawSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InventoryRawSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryRawSnapshot::class);
    }
}
