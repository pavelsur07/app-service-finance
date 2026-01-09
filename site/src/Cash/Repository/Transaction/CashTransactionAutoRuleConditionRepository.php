<?php

namespace App\Cash\Repository\Transaction;

use App\Entity\CashTransactionAutoRuleCondition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashTransactionAutoRuleCondition>
 */
class CashTransactionAutoRuleConditionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransactionAutoRuleCondition::class);
    }
}
