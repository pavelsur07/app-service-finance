<?php

declare(strict_types=1);

namespace App\Budget\Repository;

use App\Budget\Entity\BudgetRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BudgetRule>
 */
final class BudgetRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetRule::class);
    }
}
