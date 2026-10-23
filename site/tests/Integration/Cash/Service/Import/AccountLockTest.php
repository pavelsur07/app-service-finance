<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import;

use App\Cash\Exception\CashImportAccountBusyException;
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
            $this->service->import([$this->row()], $this->account, false);
            self::fail('Импорт занятого счёта должен отклоняться.');
        } catch (CashImportAccountBusyException) {
        } finally {
            $other->close();
        }

        self::assertCount(0, $this->transactionRepository->findAll());
    }

    public function testLockIsReleasedAfterImport(): void
    {
        $summary = $this->service->import([$this->row()], $this->account, false);
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
            $this->service->import([$this->row()], $this->account, false, ['preview' => true]);
        } finally {
            $other->close();
        }

        self::assertCount(0, $this->transactionRepository->findAll());
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
