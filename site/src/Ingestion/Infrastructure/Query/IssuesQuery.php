<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Webmozart\Assert\Assert;

final class IssuesQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(string $companyId, ?string $shopRef, ?int $year, ?int $month): QueryBuilder
    {
        Assert::uuid($companyId);

        $qb = $this->connection->createQueryBuilder()
            ->select(
                'i.id',
                'i.kind',
                'i.details',
                'i.created_at',
            )
            ->from('ingest_normalization_issues', 'i')
            ->innerJoin(
                'i',
                'ingest_raw_records',
                'r',
                'r.company_id = i.company_id AND r.id = i.raw_record_id',
            )
            ->where('i.company_id = :companyId')
            ->andWhere('i.resolved_at IS NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('i.created_at', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        if (null !== $shopRef && '' !== $shopRef) {
            $qb->andWhere('r.shop_ref = :shopRef')
                ->setParameter('shopRef', $shopRef);
        }

        if (null !== $year && null !== $month) {
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
            $qb->andWhere('i.created_at >= :from')
                ->andWhere('i.created_at < :toExclusive')
                ->setParameter('from', $from, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
                ->setParameter('toExclusive', $from->modify('first day of next month'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);
        }

        return $qb;
    }

    public function count(string $companyId, ?string $shopRef, ?int $year, ?int $month): int
    {
        $qb = $this->build($companyId, $shopRef, $year, $month);
        $qb->select('COUNT(i.id) AS total_results')
            ->resetOrderBy();

        return (int) $qb->executeQuery()->fetchOne();
    }
}
