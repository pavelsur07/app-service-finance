<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Регрессия: импорт сбрасывает пачку каждые 200 строк, и сброс отсоединял
 * компанию, счёт и журнал — файл длиннее 200 строк обрезался на первой пачке
 * (на проде 23 задачи ровно по 200 строк, ни одного импорта больше).
 */
final class CashFileImportBatchBoundaryTest extends CashFileImportTestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function rowCounts(): iterable
    {
        yield 'one batch plus one' => [201];
        yield 'two batches plus one' => [401];
    }

    #[DataProvider('rowCounts')]
    public function testImportCrossingBatchBoundaryCreatesEveryRow(int $rowCount): void
    {
        $jobId = (string) $this->queueCsvImport($this->distinctRows($rowCount))->getId();

        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        $log = $this->reloadLog($job);
        self::assertSame($rowCount, $log->getCreatedCount());
        self::assertNotNull($log->getFinishedAt());
        self::assertSame($rowCount, $this->countActiveTransactions());

        // Остатки пересчитаны по всем строкам: сумма 1001..(1000+N) приходом за день.
        $expectedInflow = number_format($rowCount * 1000 + $rowCount * ($rowCount + 1) / 2, 2, '.', '');
        $inflow = $this->em->getConnection()->fetchOne(
            'SELECT inflow FROM money_account_daily_balance WHERE money_account_id = :accountId AND date = :date',
            ['accountId' => $this->account->getId(), 'date' => '2026-09-01'],
        );
        self::assertSame($expectedInflow, number_format((float) $inflow, 2, '.', ''));
    }

    public function testRepeatedImportAcrossBatchesSkipsEveryRow(): void
    {
        $rows = $this->distinctRows(201);
        $this->handle((string) $this->queueCsvImport($rows)->getId());

        $secondId = (string) $this->queueCsvImport($rows)->getId();
        $this->handle($secondId);

        $job = $this->reloadJob($secondId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        $log = $this->reloadLog($job);
        self::assertSame(0, $log->getCreatedCount());
        self::assertSame(201, $log->getSkippedDuplicates());
        self::assertSame(201, $this->countActiveTransactions());
    }

    public function testCounterpartyCreatedInFirstBatchIsReusedInSecond(): void
    {
        $rows = $this->distinctRows(250, [
            0 => ['counterparty' => 'ООО "Ромашка"'],
            249 => ['counterparty' => 'ООО "Ромашка"'],
        ]);

        $jobId = (string) $this->queueCsvImport($rows)->getId();
        $this->handle($jobId);

        self::assertSame(CashFileImportJob::STATUS_DONE, $this->reloadJob($jobId)->getStatus());
        $counterparties = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM counterparty WHERE company_id = :companyId',
            ['companyId' => $this->company->getId()],
        );
        self::assertSame(1, $counterparties);
        $linked = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(DISTINCT counterparty_id) FROM cash_transaction WHERE company_id = :companyId AND counterparty_id IS NOT NULL',
            ['companyId' => $this->company->getId()],
        );
        self::assertSame(1, $linked);
    }
}
