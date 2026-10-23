<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import;

use App\Cash\Application\ImportBank1CStatementAction;
use App\Cash\Exception\CashImportAccountBusyException;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\DBAL\DriverManager;

/**
 * Импорт 1С в счёт, в который уже идёт другой импорт (файл, 1С, банк), не запускается
 * параллельно: поиск дубля по externalId и вставка не атомарны, уникальный индекс импорта 1С
 * не защищает.
 */
final class AccountLockTest extends ClientBank1CImportServiceTestCase
{
    public function testImportIntoAccountBeingImportedIsRejected(): void
    {
        // Другой импорт держит блокировку счёта в своей сессии.
        $other = DriverManager::getConnection($this->connection->getParams());
        self::assertTrue((bool) $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
            ['key' => $this->company->getId().':'.$this->account->getId()],
        ));

        try {
            $this->service->import([$this->row()], $this->account, false, fn () => $this->em->flush());
            self::fail('Импорт занятого счёта должен отклоняться.');
        } catch (CashImportAccountBusyException) {
        } finally {
            $other->close();
        }

        self::assertCount(0, $this->transactionRepository->findAll());
    }

    public function testLockIsReleasedAfterImport(): void
    {
        $summary = $this->service->import([$this->row()], $this->account, false, fn () => $this->em->flush());
        self::assertSame(1, $summary['created']);

        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            self::assertTrue((bool) $other->fetchOne(
                "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
                ['key' => $this->company->getId().':'.$this->account->getId()],
            ));
        } finally {
            $other->close();
        }
    }

    public function testPreviewDoesNotTakeLock(): void
    {
        $other = DriverManager::getConnection($this->connection->getParams());
        self::assertTrue((bool) $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
            ['key' => $this->company->getId().':'.$this->account->getId()],
        ));

        try {
            $this->service->import([$this->row()], $this->account, false, fn () => $this->em->flush(), ['preview' => true]);
        } finally {
            $other->close();
        }

        self::assertCount(0, $this->transactionRepository->findAll());
    }

    public function testActionSavesImportLogWithRows(): void
    {
        $result = $this->action()($this->company, [$this->row()], $this->account, false, null, 'statement.txt', []);
        $this->em->clear();

        self::assertSame(1, $result['summary']['created']);
        self::assertSame(
            ['created_count' => 1, 'finished' => true],
            $this->storedLog($result['importLog']->getId()),
        );
        self::assertCount(1, $this->transactionRepository->findAll());
    }

    public function testActionSavesImportLogWhenAccountIsBusy(): void
    {
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('cash_import_account'), hashtext(:key))",
            ['key' => $this->company->getId().':'.$this->account->getId()],
        );

        try {
            $this->action()($this->company, [$this->row()], $this->account, false, null, 'statement.txt', []);
            self::fail('Импорт занятого счёта должен отклоняться.');
        } catch (CashImportAccountBusyException) {
        } finally {
            $other->close();
        }

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM import_log WHERE company_id = :company AND finished_at IS NOT NULL AND created_count = 0',
            ['company' => $this->company->getId()],
        ));
        self::assertCount(0, $this->transactionRepository->findAll());
    }

    private function action(): ImportBank1CStatementAction
    {
        return new ImportBank1CStatementAction($this->service, self::getContainer()->get(ImportLogger::class), $this->em);
    }

    /**
     * @return array{created_count: int, finished: bool}
     */
    private function storedLog(string $logId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT created_count, finished_at FROM import_log WHERE id = :id',
            ['id' => $logId],
        );
        self::assertIsArray($row);

        return ['created_count' => (int) $row['created_count'], 'finished' => null !== $row['finished_at']];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'docType' => 'Банковский ордер',
            'docNumber' => '861307',
            'docDate' => '2026-01-29',
            'amount' => 1099,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '40702810900000000002',
            'dateDebit' => '2026-01-29',
            'dateCredit' => null,
            'purpose' => 'Покупка товара',
            'direction' => 'outflow',
        ];
    }
}
