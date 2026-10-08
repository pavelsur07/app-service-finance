<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Integration\Cash\Service\Import\File\CashFileImportTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия: с 2026-02-08 каждый файловый импорт, создавший хотя бы одну строку,
 * оставался в processing — строки записаны, а задача и журнал не закрыты
 * (на проде 66 задач). Проверяется полный путь сообщение → handler → статус.
 */
final class CashFileImportHandlerFlowTest extends CashFileImportTestCase
{
    public function testSingleRowImportFinishesJobAndLog(): void
    {
        $job = $this->queueCsvImport($this->distinctRows(1));
        $jobId = (string) $job->getId();

        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        $log = $this->reloadLog($job);
        self::assertSame(1, $log->getCreatedCount());
        self::assertNotNull($log->getFinishedAt());
        self::assertSame(1, $this->countActiveTransactions());
    }

    public function testImportWithOnlyDuplicatesStillFinishes(): void
    {
        $rows = $this->distinctRows(3);
        $this->handle((string) $this->queueCsvImport($rows)->getId());

        $secondId = (string) $this->queueCsvImport($rows)->getId();
        $this->handle($secondId);

        $job = $this->reloadJob($secondId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        $log = $this->reloadLog($job);
        self::assertSame(0, $log->getCreatedCount());
        self::assertSame(3, $log->getSkippedDuplicates());
        self::assertSame(3, $this->countActiveTransactions());
    }

    /**
     * Номер документа повторяется (нумерация с начала года, разные счета), а
     * uniq_cashflow_import (company_id, import_source, external_id) валил весь импорт.
     */
    public function testRepeatedDocNumberInOneFileImportsBothRows(): void
    {
        $jobId = (string) $this->queueCsvImport($this->distinctRows(2, [
            0 => ['doc_number' => '17'],
            1 => ['doc_number' => '17'],
        ]))->getId();

        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        self::assertSame(2, $this->countActiveTransactions());
        $docNumbers = $this->em->getConnection()->fetchFirstColumn(
            'SELECT doc_number FROM cash_transaction WHERE company_id = :companyId',
            ['companyId' => $this->company->getId()],
        );
        self::assertSame(['17', '17'], $docNumbers, 'Номер документа сохраняется в doc_number.');
    }

    public function testDocNumberHeldByEarlierFileImportDoesNotBlockImport(): void
    {
        // Строки, импортированные до фикса, держат номер в external_id.
        $legacy = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withAmount('500.00')
            ->build();
        $legacy->setImportSource('file');
        $legacy->setExternalId('17');
        $this->em->persist($legacy);
        $this->em->flush();

        $jobId = (string) $this->queueCsvImport($this->distinctRows(1, [0 => ['doc_number' => '17']]))->getId();
        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        self::assertSame(2, $this->countActiveTransactions());
    }

    /**
     * Ошибка БД закрывает EntityManager; раньше handler падал на flush закрытого EM,
     * retry выходил на проверке статуса, и задача навсегда оставалась в processing.
     */
    public function testDatabaseErrorDuringImportMarksJobFailed(): void
    {
        // Сумма за пределами numeric(18,2): flush падает на стороне PostgreSQL.
        $jobId = (string) $this->queueCsvImport([
            ['date' => '01.09.2026', 'amount' => '100000000000000000,00', 'description' => 'Переполнение'],
        ])->getId();

        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_FAILED, $job->getStatus());
        self::assertStringContainsString('Exception', (string) $job->getErrorMessage());
        self::assertNotNull($this->reloadLog($job)->getFinishedAt());
        self::assertSame(0, $this->countActiveTransactions());
    }
}
