<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Repository;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesRnpDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WildberriesRnpDaily>
 */
final class WildberriesRnpDailyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WildberriesRnpDaily::class);
    }

    /**
     * @param array{sku?: list<string>, brand?: list<string>, category?: list<string>} $filters
     *
     * @return list<WildberriesRnpDaily>
     */
    public function findRangeByCompany(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->andWhere('r.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('r.date', 'ASC')
            ->addOrderBy('r.sku', 'ASC');

        $this->applyFilters($qb, $filters);

        /** @var list<WildberriesRnpDaily> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param array{sku?: list<string>, brand?: list<string>, category?: list<string>} $filters
     *
     * @return array{
     *     orders_count_spp_total: int,
     *     orders_sum_spp_minor_total: int,
     *     sales_count_spp_total: int,
     *     sales_sum_spp_minor_total: int,
     *     ad_cost_sum_minor_total: int,
     *     cogs_sum_spp_minor_total: int,
     *     buyout_rate_weighted: string
     * }
     */
    public function sumRangeByCompany(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.ordersCountSpp), 0) AS orders_count_spp_total')
            ->addSelect('COALESCE(SUM(r.ordersSumSppMinor), 0) AS orders_sum_spp_minor_total')
            ->addSelect('COALESCE(SUM(r.salesCountSpp), 0) AS sales_count_spp_total')
            ->addSelect('COALESCE(SUM(r.salesSumSppMinor), 0) AS sales_sum_spp_minor_total')
            ->addSelect('COALESCE(SUM(r.adCostSumMinor), 0) AS ad_cost_sum_minor_total')
            ->addSelect('COALESCE(SUM(r.cogsSumSppMinor), 0) AS cogs_sum_spp_minor_total')
            ->addSelect('COALESCE(SUM(r.buyoutRate * r.salesCountSpp) / NULLIF(SUM(r.salesCountSpp), 0), 0) AS buyout_rate_weighted')
            ->andWhere('r.company = :company')
            ->andWhere('r.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE);

        $this->applyFilters($qb, $filters);

        /** @var array<string, mixed>|null $result */
        $result = $qb->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (null === $result) {
            return [
                'orders_count_spp_total' => 0,
                'orders_sum_spp_minor_total' => 0,
                'sales_count_spp_total' => 0,
                'sales_sum_spp_minor_total' => 0,
                'ad_cost_sum_minor_total' => 0,
                'cogs_sum_spp_minor_total' => 0,
                'buyout_rate_weighted' => '0',
            ];
        }

        return [
            'orders_count_spp_total' => (int) $result['orders_count_spp_total'],
            'orders_sum_spp_minor_total' => (int) $result['orders_sum_spp_minor_total'],
            'sales_count_spp_total' => (int) $result['sales_count_spp_total'],
            'sales_sum_spp_minor_total' => (int) $result['sales_sum_spp_minor_total'],
            'ad_cost_sum_minor_total' => (int) $result['ad_cost_sum_minor_total'],
            'cogs_sum_spp_minor_total' => (int) $result['cogs_sum_spp_minor_total'],
            'buyout_rate_weighted' => (string) ($result['buyout_rate_weighted'] ?? '0'),
        ];
    }

    /**
     * @param array{sku?: list<string>, brand?: list<string>, category?: list<string>} $filters
     *
     * @return list<array{
     *     date: \DateTimeInterface,
     *     sku: string,
     *     category: ?string,
     *     brand: ?string,
     *     orders_count_spp: string,
     *     orders_sum_spp_minor: string,
     *     sales_count_spp: string,
     *     sales_sum_spp_minor: string,
     *     ad_cost_sum_minor: string,
     *     buyout_rate: string,
     *     cogs_sum_spp_minor: string
     * }>
     */
    public function findRangeGroupedDaySku(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.date AS date')
            ->addSelect('r.sku AS sku')
            ->addSelect('r.category AS category')
            ->addSelect('r.brand AS brand')
            ->addSelect('r.ordersCountSpp AS orders_count_spp')
            ->addSelect('r.ordersSumSppMinor AS orders_sum_spp_minor')
            ->addSelect('r.salesCountSpp AS sales_count_spp')
            ->addSelect('r.salesSumSppMinor AS sales_sum_spp_minor')
            ->addSelect('r.adCostSumMinor AS ad_cost_sum_minor')
            ->addSelect('r.buyoutRate AS buyout_rate')
            ->addSelect('r.cogsSumSppMinor AS cogs_sum_spp_minor')
            ->andWhere('r.company = :company')
            ->andWhere('r.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('to', $to->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('r.date', 'ASC')
            ->addOrderBy('r.sku', 'ASC');

        $this->applyFilters($qb, $filters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return $rows;
    }

    /**
     * @param array{sku?: list<string>, brand?: list<string>, category?: list<string>} $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['sku'])) {
            $qb->andWhere($qb->expr()->in('r.sku', ':sku'))
                ->setParameter('sku', $filters['sku'], Connection::PARAM_STR_ARRAY);
        }

        if (!empty($filters['brand'])) {
            $qb->andWhere($qb->expr()->in('r.brand', ':brand'))
                ->setParameter('brand', $filters['brand'], Connection::PARAM_STR_ARRAY);
        }

        if (!empty($filters['category'])) {
            $qb->andWhere($qb->expr()->in('r.category', ':category'))
                ->setParameter('category', $filters['category'], Connection::PARAM_STR_ARRAY);
        }
    }
}
