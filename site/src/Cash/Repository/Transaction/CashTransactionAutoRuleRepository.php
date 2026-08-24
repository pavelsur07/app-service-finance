<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

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
     * Полный список правил компании без пагинации — для фасада и MCP-инструмента.
     *
     * @return CashTransactionAutoRule[]
     */
    public function findByCompany(
        Company $company,
        ?CashTransactionAutoRuleAction $action = null,
        ?CashTransactionAutoRuleOperationType $operationType = null,
        ?CashflowCategory $category = null,
    ): array {
        return $this->createFilteredQueryBuilder($company, $action, $operationType, $category)
            ->getQuery()
            ->getResult();
    }

    public function countByCompany(Company $company): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Pagerfanta<CashTransactionAutoRule>
     */
    public function paginateByCompany(
        Company $company,
        ?CashTransactionAutoRuleAction $action,
        ?CashTransactionAutoRuleOperationType $operationType,
        ?CashflowCategory $category,
        ?string $search,
        ?bool $isActive,
        int $page,
        int $perPage,
    ): Pagerfanta {
        $pager = new Pagerfanta(new QueryAdapter($this->createFilteredQueryBuilder(
            $company,
            $action,
            $operationType,
            $category,
            $search,
            $isActive,
        )));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        // Страница за пределами диапазона прижимается к последней: иначе ?page=999
        // показывал бы «ничего не найдено» рядом с ненулевым счётчиком.
        $pager->setCurrentPage(min(max(1, $page), max(1, $pager->getNbPages())));

        return $pager;
    }

    private function createFilteredQueryBuilder(
        Company $company,
        ?CashTransactionAutoRuleAction $action = null,
        ?CashTransactionAutoRuleOperationType $operationType = null,
        ?CashflowCategory $category = null,
        ?string $search = null,
        ?bool $isActive = null,
    ): QueryBuilder {
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

        if (null !== $isActive) {
            $qb->andWhere('r.isActive = :isActive')->setParameter('isActive', $isActive);
        }

        $search = null === $search ? '' : trim($search);
        if ('' !== $search) {
            // Условия проверяются подзапросом, а не join'ом: у правила с тремя
            // подходящими условиями join размножил бы строку и сломал бы счётчик
            // пагинации. Служебные символы LIKE экранируются, иначе запрос «100%»
            // нашёл бы вообще всё.
            $conditionMatch = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(CashTransactionAutoRuleCondition::class, 'c')
                ->leftJoin('c.counterparty', 'ccp')
                ->where('c.autoRule = r')
                ->andWhere('LOWER(c.value) LIKE :search OR LOWER(ccp.name) LIKE :search');

            $qb->leftJoin('r.counterparty', 'cp')
                ->andWhere($qb->expr()->orX(
                    'LOWER(r.name) LIKE :search',
                    'LOWER(cp.name) LIKE :search',
                    $qb->expr()->exists($conditionMatch->getDQL()),
                ))
                ->setParameter('search', '%'.mb_strtolower(addcslashes($search, '%_\\'), 'UTF-8').'%');
        }

        return $qb;
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
