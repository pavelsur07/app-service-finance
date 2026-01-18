<?php

namespace App\Cash\Repository\PaymentPlan;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Entity\PaymentPlanMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentPlanMatch>
 */
class PaymentPlanMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentPlanMatch::class);
    }

    public function findOneByTransaction(CashTransaction $transaction): ?PaymentPlanMatch
    {
        return $this->findOneBy(['transaction' => $transaction]);
    }
}
