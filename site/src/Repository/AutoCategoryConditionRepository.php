<?php

namespace App\Repository;

use App\Entity\AutoCategoryCondition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutoCategoryCondition>
 */
class AutoCategoryConditionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutoCategoryCondition::class);
    }
}
