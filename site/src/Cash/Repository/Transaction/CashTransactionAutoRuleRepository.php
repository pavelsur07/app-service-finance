<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Entity\Company;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleOperationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashTransactionAutoRule>
 */
class CashTransactionAutoRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransactionAutoRule::class);
    }

    /**
     * @return CashTransactionAutoRule[]
     */
    public function findByCompany(
        Company $company,
        ?CashTransactionAutoRuleAction $action = null,
        ?CashTransactionAutoRuleOperationType $operationType = null,
        ?CashflowCategory $category = null,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.name', 'ASC');

        if ($action) {
            $qb->andWhere('r.action = :action')->setParameter('action', $action);
        }

        if ($operationType) {
            $qb->andWhere('r.operationType = :operationType')
                ->setParameter('operationType', $operationType);
        }

        if ($category) {
            $qb->andWhere('r.cashflowCategory = :category')->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }
}
