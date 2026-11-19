<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashflowCategory>
 */
class CashflowCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashflowCategory::class);
    }

    /**
     * @return CashflowCategory[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.sort', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Возвращает список категорий в порядке вложенности.
     *
     * Один запрос: дети подгружаются fetch-join'ом, поэтому обход дерева и
     * `getChildren()` у результата не ходят в БД (раньше — запрос на каждый узел).
     *
     * @return CashflowCategory[]
     */
    public function findTreeByCompany(Company $company): array
    {
        /** @var CashflowCategory[] $categories */
        $categories = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')
            ->addSelect('ch')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.sort', \SortDirection::Ascending)
            ->addOrderBy('c.id', \SortDirection::Ascending)
            ->addOrderBy('ch.sort', \SortDirection::Ascending)
            ->addOrderBy('ch.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($categories as $category) {
            if (null === $category->getParent()) {
                $this->collectTree($category, $result);
            }
        }

        return $result;
    }

    public function findSystemUnallocatedByCompany(Company $company): ?CashflowCategory
    {
        return $this->findOneByCompanyAndCode($company, CashflowCategory::CODE_UNALLOCATED)
            ?? $this->findOneByCompanyAndCode($company, CashflowCategory::SYSTEM_UNALLOCATED);
    }

    /**
     * Категория по идентификатору строго в пределах компании.
     *
     * Обычный find() здесь запрещён: идентификатор приходит из формы, и без скоупа
     * по компании чужую статью можно было бы привязать к своей транзакции.
     */
    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashflowCategory
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('IDENTITY(c.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCompanyAndCode(Company $company, string $code): ?CashflowCategory
    {
        return $this->findOneBy([
            'company' => $company,
            'systemCode' => $code,
        ]);
    }

    /**
     * Все статьи компании под FOR UPDATE со свежим состоянием (родитель, код): правка формой
     * ждёт commit вызывающего, а уже загруженные сущности перечитываются из БД.
     * Только внутри транзакции вызывающего.
     *
     * @return list<CashflowCategory>
     */
    public function lockTreeByCompany(Company $company): array
    {
        /** @var list<CashflowCategory> $rows */
        $rows = $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /**
     * Сохранённые в БД код консолидации и родитель статьи, строка под FOR UPDATE: форма могла
     * загрузить статью до того, как ей проставили код. Только внутри транзакции вызывающего.
     *
     * @return array{groupCode: ?string, parentId: ?string}|null null — статьи ещё нет в БД
     */
    public function lockStoredPlacement(string $companyId, string $id): ?array
    {
        Assert::uuid($companyId);
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT group_code, parent_id FROM "cashflow_categories" WHERE id = :id AND company_id = :companyId FOR UPDATE',
            ['id' => $id, 'companyId' => $companyId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'groupCode' => null === $row['group_code'] ? null : (string) $row['group_code'],
            'parentId' => null === $row['parent_id'] ? null : (string) $row['parent_id'],
        ];
    }

    /**
     * Блокировка дерева статей компании — тот же ключ у формы, загрузки из JSON, синхронизации с
     * эталоном группы и миграции дерева — и сохранённые в БД родитель и код каждой статьи.
     * Только внутри транзакции вызывающего: advisory lock держится до её commit.
     *
     * @return array<string, array{parentId: ?string, code: ?string, flowKind: string}> id статьи → размещение
     */
    public function lockTreePlacements(string $companyId): array
    {
        $this->lockTree($companyId);

        $placements = [];
        foreach ($this->getEntityManager()->getConnection()->iterateAssociative('SELECT id, parent_id, system_code, flow_kind FROM "cashflow_categories" WHERE company_id = :companyId', ['companyId' => $companyId]) as $row) {
            $placements[(string) $row['id']] = [
                'parentId' => null === $row['parent_id'] ? null : (string) $row['parent_id'],
                'code' => null === $row['system_code'] ? null : (string) $row['system_code'],
                'flowKind' => (string) $row['flow_kind'],
            ];
        }

        return $placements;
    }

    /**
     * Какие из id заняты статьями других компаний: такой id в файле загрузки — отказ, а не новая статья.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function findIdsOutsideCompany(string $companyId, array $ids): array
    {
        Assert::uuid($companyId);
        if ([] === $ids) {
            return [];
        }

        /** @var list<string> $taken */
        $taken = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM "cashflow_categories" WHERE id IN (:ids) AND company_id <> :companyId',
            ['ids' => $ids, 'companyId' => $companyId],
            ['ids' => ArrayParameterType::STRING],
        );

        return $taken;
    }

    /** Только блокировка дерева компании (см. lockTreePlacements()); внутри транзакции вызывающего. */
    public function lockTree(string $companyId): void
    {
        Assert::uuid($companyId);
        $this->getEntityManager()->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:companyId))', ['companyId' => $companyId]);
    }

    /**
     * Есть ли у компании хоть одна несистемная статья ДДС — признак, что дерево уже своё.
     */
    public function hasRegularByCompany(Company $company): bool
    {
        return null !== $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.company = :company')
            ->andWhere('c.isSystem = false')
            ->setParameter('company', $company)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function collectTree(CashflowCategory $category, array &$result): void
    {
        $result[] = $category;
        foreach ($category->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }
}
