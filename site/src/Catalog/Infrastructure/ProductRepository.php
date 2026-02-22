<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\DTO\ProductListFilter;
use App\Catalog\Entity\Product;
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
}
