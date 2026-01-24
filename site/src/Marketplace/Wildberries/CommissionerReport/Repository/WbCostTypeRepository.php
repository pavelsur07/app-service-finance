<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Repository;

use App\Marketplace\Wildberries\Entity\CommissionerReport\WbCostType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WbCostType>
 */
final class WbCostTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WbCostType::class);
    }
}
