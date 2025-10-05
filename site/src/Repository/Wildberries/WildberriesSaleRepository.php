<?php

namespace App\Repository\Wildberries;

use App\Entity\Company;
use App\Entity\Wildberries\WildberriesSale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WildberriesSale>
 */
class WildberriesSaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesSale::class);
    }

    public function findOneByCompanyAndSrid(Company $company, string $srid): ?WildberriesSale
    {
        return $this->createQueryBuilder('sale')
            ->andWhere('sale.company = :company')
            ->andWhere('sale.srid = :srid')
            ->setParameter('company', $company)
            ->setParameter('srid', $srid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<WildberriesSale>, total: int, currentPage: int, pages: int, perPage: int}
     */
    public function paginateByCompany(Company $company, int $page, int $perPage = 50, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $qb = $this->createQueryBuilder('sale')
            ->andWhere('sale.company = :company')
            ->setParameter('company', $company);

        if (!empty($filters['status'])) {
            $qb->andWhere('sale.saleStatus = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['orderType'])) {
            $qb->andWhere('sale.orderType = :orderType')
                ->setParameter('orderType', $filters['orderType']);
        }

        if (!empty($filters['from'])) {
            $qb->andWhere('sale.soldAt >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $qb->andWhere('sale.soldAt <= :to')
                ->setParameter('to', $filters['to']);
        }

        $qb->orderBy('sale.statusUpdatedAt', 'DESC')
            ->addOrderBy('sale.soldAt', 'DESC');

        $query = $qb->getQuery();
        $query->setMaxResults($perPage);
        $query->setFirstResult(($page - 1) * $perPage);

        $paginator = new Paginator($query, true);
        $total = count($paginator);
        $pages = (int) ceil($total / $perPage);

        if ($pages > 0 && $page > $pages) {
            $page = $pages;
            $query->setFirstResult(($page - 1) * $perPage);
            $paginator = new Paginator($query, true);
        }

        return [
            'items' => iterator_to_array($paginator, false),
            'total' => $total,
            'currentPage' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
        ];
    }
}
