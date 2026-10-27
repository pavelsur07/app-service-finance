<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    private function collectTree(CashflowCategory $category, array &$result): void
    {
        $result[] = $category;
        foreach ($category->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }
}
