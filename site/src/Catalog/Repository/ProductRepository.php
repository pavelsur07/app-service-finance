<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Найти продукт по ID с проверкой принадлежности к компании
     *
     * КРИТИЧНО: ВСЕГДА проверяем company_id для безопасности!
     *
     * @param string $productId UUID продукта
     * @param string $companyId UUID компании
     * @return Product|null
     */
    public function findByIdAndCompany(string $productId, string $companyId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->andWhere('p.company = :company')  // ← КРИТИЧНО для безопасности!
            ->setParameter('id', $productId)
            ->setParameter('company', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Product[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySku(Company $company, string $sku): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->andWhere('p.sku = :sku')
            ->setParameter('company', $company)
            ->setParameter('sku', $sku)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Product[]
     */
    public function findActiveProducts(Company $company): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.company = :company')
            ->andWhere('p.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', ProductStatus::ACTIVE)
            ->getQuery()
            ->getResult();
    }
}
