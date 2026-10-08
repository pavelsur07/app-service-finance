<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Tests\Integration\Cash\Service\Import\File\CashFileImportTestCase;

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
}
