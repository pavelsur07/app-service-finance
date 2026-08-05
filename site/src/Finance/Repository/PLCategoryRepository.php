<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PLCategoryRepository extends ServiceEntityRepository
{
    private const SORT_ORDER_STEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLCategory::class);
    }

    /**
     * @return PLCategory[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PLCategory[]
     */
    public function findTreeByCompany(Company $company): array
    {
        $roots = $this->findRootByCompany($company);
        $result = [];
        foreach ($roots as $root) {
            $this->collectTree($root, $result);
        }

        return $result;
    }

    private function collectTree(PLCategory $category, array &$result): void
    {
        $result[] = $category;
        foreach ($category->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }

    /**
     * @return string[]
     */
    public function findCodesByCompany(Company $company): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.code AS code')
            ->andWhere('c.company = :company')
            ->andWhere('c.code IS NOT NULL')
            ->andWhere("c.code <> ''")
            ->setParameter('company', $company)
            ->orderBy('c.code', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * Формулы категорий компании: id → формула. Нужны, чтобы увидеть ссылки,
     * которые ломает импорт, у категорий, которых в переносимом дереве нет.
     *
     * @return array<string, string>
     */
    public function findFormulasByCompany(Company $company): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id AS id, c.formula AS formula')
            ->andWhere('c.company = :company')
            ->andWhere('c.formula IS NOT NULL')
            ->andWhere("c.formula <> ''")
            ->setParameter('company', $company)
            ->getQuery()
            ->getArrayResult();

        $formulas = [];
        foreach ($rows as $row) {
            $formulas[(string) $row['id']] = (string) $row['formula'];
        }

        return $formulas;
    }

    public function getNextSortOrder(Company $company, ?PLCategory $parent): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MAX(c.sortOrder) as maxSortOrder')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company);

        if ($parent) {
            $qb->andWhere('c.parent = :parent')
                ->setParameter('parent', $parent);
        } else {
            $qb->andWhere('c.parent IS NULL');
        }

        $maxSortOrder = $qb->getQuery()->getSingleScalarResult();
        $maxSortOrder = null !== $maxSortOrder ? (int) $maxSortOrder : null;

        return ($maxSortOrder ?? 0) + self::SORT_ORDER_STEP;
    }
}
