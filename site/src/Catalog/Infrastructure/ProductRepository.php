<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\DTO\ProductListFilter;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

final class ProductRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return Pagerfanta<Product>
     */
    public function paginateForCompany(ProductListFilter $filter, int $page, int $perPage): Pagerfanta
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');

        if (null !== $filter->companyId) {
            $qb
                ->andWhere('IDENTITY(p.company) = :companyId')
                ->setParameter('companyId', $filter->companyId);
        }

        if (null !== $filter->search) {
            $qb
                ->andWhere('(LOWER(p.name) LIKE :search OR LOWER(p.sku) LIKE :search)')
                ->setParameter('search', '%'.mb_strtolower($filter->search).'%');
        }

        if (null !== $filter->status) {
            $qb
                ->andWhere('p.status = :status')
                ->setParameter('status', $filter->status);
        }

        $qb->orderBy('p.updatedAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setCurrentPage(max(1, $page));
        $pager->setMaxPerPage(max(1, $perPage));

        return $pager;
    }

    public function existsSkuForCompany(string $sku, string $companyId): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.sku = :sku')
            ->setParameter('companyId', $companyId)
            ->setParameter('sku', $sku)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function existsSkuForCompanyExcludingProductId(string $sku, string $companyId, string $productId): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.sku = :sku')
            ->andWhere('p.id != :productId')
            ->setParameter('companyId', $companyId)
            ->setParameter('sku', $sku)
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function getOneForCompanyByIdsOrNull(string $companyId, string $productId): ?Product
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('p.id = :productId')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->setParameter('productId', $productId)
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIdAndCompany(string $productId, string $companyId): ?Product
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('p.id = :id')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->setParameter('id', $productId)
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Возвращает Set vendorSku компании в нижнем регистре для дедубликации при импорте.
     * Загружается одним запросом перед батчем — защита от N+1.
     *
     * @return array<string, true>
     */
    public function findVendorSkuSetByCompany(string $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.vendorSku')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.vendorSku IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[strtolower($row['vendorSku'])] = true;
        }

        return $set;
    }

    /**
     * @return Product[]
     */
    public function findByCompanyId(string $companyId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySkuAndCompanyId(string $sku, string $companyId): ?Product
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.sku = :sku')
            ->setParameter('companyId', $companyId)
            ->setParameter('sku', $sku)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Product[]
     */
    public function findActiveByCompanyId(string $companyId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', ProductStatus::ACTIVE)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
