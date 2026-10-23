<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Entity\Import\CashFileImportJob;
use Doctrine\DBAL\Connection;

/**
 * Компании, которые обходит ночная проверка целостности ДДС: сами предикаты — в действиях ремонта
 * (`CloseLostCashFileImportJobsAction`, `RecalculateAllocatedAmountsAction`, `CloseOpenImportLogsAction`),
 * здесь только охват.
 */
final readonly class CashIntegrityCompaniesQuery
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Компании с заданиями файлового импорта в `queued`, созданными раньше порога.
     *
     * @return list<string>
     */
    public function withQueuedImportJobsBefore(\DateTimeImmutable $createdBefore): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT company_id::text FROM cash_file_import_jobs WHERE status = :queued AND created_at < :before ORDER BY 1',
            ['queued' => CashFileImportJob::STATUS_QUEUED, 'before' => $createdBefore->format('Y-m-d H:i:s')],
        ));
    }

    /**
     * Компании с журналами импорта без `finished_at`, начатыми раньше порога (UTC, как пишет
     * `ImportLogger`).
     *
     * @return list<string>
     */
    public function withOpenImportLogsBefore(\DateTimeImmutable $startedBefore): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT company_id::text FROM import_log WHERE finished_at IS NULL AND started_at < :before ORDER BY 1',
            ['before' => $startedBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        ));
    }

    /**
     * Компании с живыми транзакциями ДДС.
     *
     * @return list<string>
     */
    public function withLiveTransactions(): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT company_id::text FROM cash_transaction WHERE deleted_at IS NULL ORDER BY 1',
        ));
    }
}
