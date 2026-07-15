<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
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

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashTransactionAutoRule
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('IDENTITY(r.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
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
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC');

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

    /**
     * @return CashTransactionAutoRule[]
     */
    public function findActiveByCompany(Company $company): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->andWhere('r.isActive = true')
            ->setParameter('company', $company)
            ->leftJoin('r.conditions', 'conditions')
            ->leftJoin('conditions.counterparty', 'conditionCounterparty')
            ->leftJoin('r.cashflowCategory', 'cashflowCategory')
            ->leftJoin('r.projectDirection', 'projectDirection')
            ->leftJoin('r.counterparty', 'counterparty')
            ->addSelect('conditions', 'conditionCounterparty', 'cashflowCategory', 'projectDirection', 'counterparty')
            ->orderBy('r.priority', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
