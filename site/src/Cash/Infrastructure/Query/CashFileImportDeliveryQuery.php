<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final readonly class CashFileImportDeliveryQuery
{
    private const ELIGIBLE = "company_id = :company AND status = 'queued' AND created_at < :before";
    private const NOT_FAILED = "NOT EXISTS (SELECT 1 FROM messenger_messages m WHERE m.queue_name = 'failed' AND strpos(m.body, j.id::text) > 0)";

    public function __construct(private Connection $db)
    {
    }

    /** @return list<string> Includes skipped failed messages so the cursor always advances. */
    public function queuedPage(string $companyId, \DateTimeImmutable $createdBefore, ?string $cursor): array
    {
        $params = ['company' => $companyId, 'before' => $createdBefore->format('Y-m-d H:i:s')];
        $after = '';
        if (null !== $cursor) {
            $after = ' AND id > :cursor';
            $params['cursor'] = $cursor;
        }

        return array_map('strval', $this->db->fetchFirstColumn('SELECT id::text FROM cash_file_import_jobs WHERE '.self::ELIGIBLE.$after.' ORDER BY id LIMIT 100', $params));
    }

    public function isQueuedForDelivery(string $companyId, string $jobId, \DateTimeImmutable $createdBefore): bool
    {
        return (bool) $this->db->fetchOne('SELECT EXISTS (SELECT 1 FROM cash_file_import_jobs j WHERE '.self::ELIGIBLE.' AND id = :id AND '.self::NOT_FAILED.')', ['company' => $companyId, 'before' => $createdBefore->format('Y-m-d H:i:s'), 'id' => $jobId]);
    }
}
