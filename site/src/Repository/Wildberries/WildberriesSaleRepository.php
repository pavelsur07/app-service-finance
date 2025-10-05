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

    public function hasSalesForCompany(Company $company): bool
    {
        $result = $this->createQueryBuilder('sale')
            ->select('1')
            ->andWhere('sale.company = :company')
            ->setParameter('company', $company)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }

    public function findLatestSoldAt(Company $company): ?\DateTimeImmutable
    {
        $sale = $this->createQueryBuilder('sale')
            ->andWhere('sale.company = :company')
            ->setParameter('company', $company)
            ->orderBy('sale.soldAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $sale instanceof WildberriesSale ? $sale->getSoldAt() : null;
    }

    public function findOldestOpenSoldAt(Company $company): ?\DateTimeImmutable
    {
        $query = $this->createQueryBuilder('sale')
            ->andWhere('sale.company = :company')
            ->setParameter('company', $company)
            ->orderBy('sale.soldAt', 'ASC')
            ->getQuery();

        foreach ($query->toIterable() as $sale) {
            if (!$sale instanceof WildberriesSale) {
                continue;
            }

            if (!$this->isClosedStatus($sale->getSaleStatus())) {
                return $sale->getSoldAt();
            }
        }

        return null;
    }

    private function isClosedStatus(?string $status): bool
    {
        if (null === $status || '' === $status) {
            return false;
        }

        $normalized = mb_strtolower($status);
        $keywords = [
            'deliver',
            'sale',
            'sold',
            'purchase',
            'return',
            'cancel',
            'refus',
            'выкуп',
            'куплен',
            'покуп',
            'продаж',
            'отказ',
            'отмен',
            'возврат',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
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

        $qb->orderBy('sale.soldAt', 'ASC');

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
