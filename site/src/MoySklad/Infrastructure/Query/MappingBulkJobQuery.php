<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\MappingBulkJob;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class MappingBulkJobQuery
{
    public const ITEM_STATUSES = ['accepted', 'skipped', 'conflict', 'failed', 'pending'];
    private const FIELDS = 'id, origin, scope, status, total, processed, accepted, skipped, conflicts, failed, created_at, finished_at, undo_status, undone, undo_skipped';

    public function __construct(private Connection $db)
    {
    }

    public function latest(string $companyId, string $connectionId, string $marketplace): ?MappingBulkJob
    {
        $row = $this->db->fetchAssociative('SELECT '.self::FIELDS.' FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND connection_id=? AND marketplace=?
            ORDER BY created_at DESC, id DESC LIMIT 1', [$companyId, $connectionId, $marketplace]);

        return false === $row ? null : MappingBulkJob::fromRow($row);
    }

    public function find(string $companyId, string $connectionId, string $marketplace, string $jobId): ?MappingBulkJob
    {
        $row = $this->db->fetchAssociative('SELECT '.self::FIELDS.' FROM moysklad_mapping_bulk_jobs WHERE company_id=? AND connection_id=? AND marketplace=? AND id=?',
            [$companyId, $connectionId, $marketplace, $jobId]);

        return false === $row ? null : MappingBulkJob::fromRow($row);
    }

    /** @return array{items: list<array{listing_id:string,status:string,message:?string,revision:?int,undo_status:?string}>, total: int} */
    public function items(string $companyId, string $jobId, ?string $status, int $page): array
    {
        Assert::greaterThanEq($page, 1);
        if (null !== $status) {
            Assert::inArray($status, self::ITEM_STATUSES);
        }
        $where = ' FROM moysklad_mapping_bulk_job_items WHERE company_id=:company AND job_id=:job'.(null === $status ? '' : ' AND status=:status');
        $params = ['company' => $companyId, 'job' => $jobId] + (null === $status ? [] : ['status' => $status]);
        /** @var list<array{listing_id:string,status:string,message:?string,revision:?int,undo_status:?string}> $items */
        $items = $this->db->fetchAllAssociative('SELECT listing_id, status, message, revision, undo_status'.$where.' ORDER BY listing_id LIMIT 25 OFFSET '.(($page - 1) * 25), $params);

        return ['items' => $items, 'total' => (int) $this->db->fetchOne('SELECT COUNT(*)'.$where, $params)];
    }

    /**
     * Предложения отчёта по первому сработавшему признаку: ['direct_article' => 30, …].
     *
     * @return array<string,int>
     */
    public function readyBreakdown(string $companyId, string $runId): array
    {
        $rows = $this->db->fetchAllKeyValue("SELECT COALESCE(payload->'decision'->'matchedRules'->>0, 'other'), COUNT(*) FROM moysklad_mapping_diagnostic_rows
            WHERE company_id=? AND run_id=? AND status='ready' GROUP BY 1 ORDER BY 2 DESC", [$companyId, $runId]);

        return array_map(intval(...), $rows);
    }

    /** @return array{enabled: bool, sizeCharacteristicId: ?string}|null */
    public function autoApply(string $companyId, string $connectionId, string $marketplace): ?array
    {
        $row = $this->db->fetchAssociative('SELECT enabled, size_characteristic_id FROM moysklad_mapping_auto_apply WHERE company_id=? AND connection_id=? AND marketplace=?',
            [$companyId, $connectionId, $marketplace]);

        return false === $row ? null : ['enabled' => (bool) $row['enabled'], 'sizeCharacteristicId' => $row['size_characteristic_id']];
    }
}
