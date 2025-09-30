<?php

namespace App\\Repository;

use App\\Entity\\PLMonthlySnapshot;
use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;
use Doctrine\\Persistence\\ManagerRegistry;

class PLMonthlySnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLMonthlySnapshot::class);
    }
}
