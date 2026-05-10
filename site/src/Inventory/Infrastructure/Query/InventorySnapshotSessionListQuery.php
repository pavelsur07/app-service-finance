<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Webmozart\Assert\Assert;

final class InventorySnapshotSessionListQuery
{
    public const PER_PAGE = 30;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function buildQueryBuilder(string $companyId): QueryBuilder
    {
        Assert::uuid($companyId);

        return $this->connection->createQueryBuilder()
            ->select(
                's.id',
                's.company_id',
                's.source',
                's.trigger_type',
                's.status',
                's.error_message',
                's.expected_pages',
                's.received_pages',
                's.created_at',
                's.started_at',
                's.completed_at',
            )
            ->from('inventory_snapshot_sessions', 's')
            ->where('s.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('s.created_at', 'DESC')
            ->addOrderBy('s.id', 'DESC');
    }

    public function getPage(string $companyId, int $page, int $perPage = self::PER_PAGE): Pagerfanta
    {
        $currentPage = max(1, $page);
        $currentPerPage = min(100, max(1, $perPage));

        $qb = $this->buildQueryBuilder($companyId);

        return Pagerfanta::createForCurrentPageWithMaxPerPage(
            new QueryAdapter($qb, static function (QueryBuilder $countQb): void {
                $countQb
                    ->select('COUNT(s.id) AS total_results')
                    ->resetOrderBy()
                    ->setMaxResults(1);
            }),
            $currentPage,
            $currentPerPage,
        );
    }
}
