<?php

namespace App\Repository\Ozon;

use App\Marketplace\Ozon\Entity\OzonOrderStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonOrderStatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonOrderStatusHistory::class);
    }
}
