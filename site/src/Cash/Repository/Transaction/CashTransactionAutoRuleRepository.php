<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
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
        // Список показывается в порядке появления правил: чем позже создано, тем ниже.
        // На порядок применения это не влияет — там priority, см. findActiveByCompany().
        // created_at хранится с точностью до секунды, поэтому у правил одной секунды
        // порядок доопределяется по id.
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.createdAt', 'ASC')
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

    /**
     * @return list<CashTransactionAutoRule>
     */
    public function findActiveGeneralProjectTargetsWithoutCfo(): array
    {
        /** @var list<CashTransactionAutoRule> $rules */
        $rules = $this->createQueryBuilder('rule')
            ->innerJoin('rule.company', 'company')
            ->innerJoin('rule.projectDirection', 'project')
            ->addSelect('company', 'project')
            ->andWhere('rule.isActive = true')
            ->andWhere('rule.responsibilityCenterId IS NULL')
            ->andWhere('project.systemCode = :projectCode')
            ->setParameter('projectCode', ProjectDirection::CODE_GENERAL)
            ->orderBy('company.id', 'ASC')
            ->addOrderBy('rule.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rules;
    }
}
