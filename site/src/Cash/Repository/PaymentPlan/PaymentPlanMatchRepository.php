<?php

declare(strict_types=1);

namespace App\Cash\Repository\PaymentPlan;

use App\Cash\Entity\PaymentPlan\PaymentPlanMatch;
use App\Cash\Entity\Transaction\CashTransaction;
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

    public function findOneByTransactionAndCompanyId(CashTransaction $transaction, string $companyId): ?PaymentPlanMatch
    {
        return $this->createQueryBuilder('planMatch')
            ->andWhere('planMatch.transaction = :transaction')
            ->andWhere('IDENTITY(planMatch.company) = :companyId')
            ->setParameter('transaction', $transaction)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
