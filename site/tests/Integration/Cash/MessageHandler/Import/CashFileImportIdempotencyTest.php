<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Tests\Integration\Cash\Service\Import\File\CashFileImportTestCase;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Повтор, возобновление и нарезка на пачки дают тот же набор строк, что один импорт
 * (docs/tasks/cash-pl-idempotency, этап 2).
 */
final class CashFileImportIdempotencyTest extends CashFileImportTestCase
{
    /**
     * Пачка — 200 созданных строк. Раньше дубль искался только в БД: одинаковые строки внутри
     * пачки импортировались обе, а на границе пачек вторая отсекалась.
     */
    public function testIdenticalRowsAcrossBatchBoundaryAreAllImported(): void
    {
        $rows = $this->distinctRows(199);
        $rows[] = $this->identicalRow();
        $rows[] = $this->identicalRow();

        $this->handle((string) $this->queueCsvImport($rows)->getId());

        self::assertSame(201, $this->countActiveTransactions());
    }

    public function testRepeatedFileCreatesNothingAndExtraIdenticalOperationIsAdded(): void
    {
        $this->handle((string) $this->queueCsvImport([$this->identicalRow(), $this->identicalRow()])->getId());
        self::assertSame(2, $this->countActiveTransactions());

        $repeatId = (string) $this->queueCsvImport([$this->identicalRow(), $this->identicalRow()])->getId();
        $this->handle($repeatId);
        self::assertSame(2, $this->countActiveTransactions());

        // Следующая выписка того же периода содержит третью такую же операцию.
        $this->handle((string) $this->queueCsvImport(array_fill(0, 3, $this->identicalRow()))->getId());
        self::assertSame(3, $this->countActiveTransactions());

        self::assertSame(2, $this->reloadLog($this->reloadJob($repeatId))->getSkippedDuplicates());
    }

    public function testSameRowWithAnotherDocNumberIsAnotherOperation(): void
    {
        $this->handle((string) $this->queueCsvImport([$this->identicalRow() + ['doc_number' => '101']])->getId());
        $this->handle((string) $this->queueCsvImport([$this->identicalRow() + ['doc_number' => '102']])->getId());

        self::assertSame(2, $this->countActiveTransactions());
    }

    /**
     * Выписка без колонки номера после выписки с номерами (или наоборот) — та же операция.
     */
    public function testRowWithoutDocNumberMatchesRowWithDocNumber(): void
    {
        $this->handle((string) $this->queueCsvImport([$this->identicalRow() + ['doc_number' => '101']])->getId());
        $this->handle((string) $this->queueCsvImport([$this->identicalRow()])->getId());
        $this->handle((string) $this->queueCsvImport([$this->identicalRow() + ['doc_number' => '101']])->getId());

        self::assertSame(1, $this->countActiveTransactions());
    }

    /**
     * Воркер умер в processing: сессионная блокировка счёта снялась, повторная доставка
     * дописывает недостающее вместо вечного пропуска.
     */
    public function testAbandonedProcessingJobIsResumed(): void
    {
        $rows = $this->distinctRows(3);
        // «Первая попытка» успела зафиксировать одну строку.
        $this->handle((string) $this->queueCsvImport([$rows[0]])->getId());

        $job = $this->queueCsvImport($rows);
        $job->start();
        $this->em->flush();
        $jobId = (string) $job->getId();

        $this->handle($jobId);

        $job = $this->reloadJob($jobId);
        self::assertSame(CashFileImportJob::STATUS_DONE, $job->getStatus(), (string) $job->getErrorMessage());
        self::assertSame(3, $this->countActiveTransactions());
    }

    public function testJobOfAccountBeingImportedIsDeferred(): void
    {
        $jobId = (string) $this->queueCsvImport($this->distinctRows(1))->getId();

        // Другой воркер держит блокировку счёта в своей сессии.
        $other = DriverManager::getConnection($this->connection->getParams());
        $key = $this->company->getId().':'.$this->account->getId();
        self::assertTrue((bool) $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
            ['key' => $key],
        ));

        try {
            $this->handle($jobId);
            self::fail('Импорт занятого счёта должен откладываться.');
        } catch (RecoverableMessageHandlingException) {
        } finally {
            $other->close();
        }

        self::assertSame(CashFileImportJob::STATUS_QUEUED, $this->reloadJob($jobId)->getStatus());
        self::assertSame(0, $this->countActiveTransactions());

        // Блокировка снята — следующая доставка импортирует.
        $this->handle($jobId);
        self::assertSame(1, $this->countActiveTransactions());
    }

    /**
     * @return array{date: string, amount: string, description: string}
     */
    private function identicalRow(): array
    {
        return ['date' => '02.09.2026', 'amount' => '500,00', 'description' => 'Комиссия банка'];
    }
}
