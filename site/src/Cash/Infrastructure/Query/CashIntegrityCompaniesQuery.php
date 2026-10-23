<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Entity\Import\CashFileImportJob;
use Doctrine\DBAL\Connection;

/**
 * Компании, которые обходит ночная проверка целостности ДДС: сами предикаты — в действиях ремонта
 * (`CloseLostCashFileImportJobsAction`, `RecalculateAllocatedAmountsAction`), здесь только охват.
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
