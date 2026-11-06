<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Exception\CashImportAccountBusyException;
use App\Cash\Infrastructure\Lock\CashImportLogLease;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Импорт выписки 1С: точка входа владеет коммитами — строки пишутся по одной, журнал
 * сохраняется и при отказе (занятый счёт, сбой).
 */
final class ImportBank1CStatementAction
{
    public function __construct(
        private readonly ClientBank1CImportService $importService,
        private readonly ImportLogger $importLogger,
        private readonly CashImportLogLease $importLogLease,
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Cash\Infrastructure\Lock\CashImportAccountLock $accountLock,
    ) {
    }

    /**
     * @param array<int, mixed> $preview
     * @param array<string, mixed> $context
     *
     * @return array{summary: array<string, mixed>, importLog: ImportLog}
     *
     * @throws CashImportAccountBusyException в счёт уже идёт другой импорт выписки
     */
    public function __invoke(
        Company $company,
        array $preview,
        MoneyAccount $account,
        bool $overwrite,
        ?string $userId,
        ?string $fileName,
        array $context,
    ): array {
        $importLog = $this->importLogger->start($company, 'bank1c', false, $userId, $fileName);
        // Keep the log outside the all-or-nothing import transaction.
        $this->entityManager->flush();
        // Пока импорт идёт, ремонт и ночной гейт не считают журнал брошенным.
        $this->importLogLease->acquire($importLog->getId());

        $accountLockHeld = false;
        try {
            $summary = $this->entityManager->wrapInTransaction(function () use ($preview, $account, $overwrite, $importLog, $context, $company, &$accountLockHeld): array {
                $this->entityManager->lock($company, \Doctrine\DBAL\LockMode::PESSIMISTIC_READ);
                $this->entityManager->refresh($company);
                if (!$this->accountLock->tryAcquire((string) $company->getId(), (string) $account->getId())) {
                    throw new CashImportAccountBusyException('В этот счёт уже идёт импорт выписки. Повторите через минуту.');
                }
                $accountLockHeld = true;

                return $this->importService->import($preview, $account, $overwrite, fn () => $this->entityManager->flush(), ['preview' => false, 'import_log' => $importLog, 'account_lock_owned_by_caller' => true] + $context);
            });
        } catch (\Throwable $exception) {
            // wrapInTransaction closes EM on failure; finalize the retained log after rollback.
            if (!$this->entityManager->isOpen()) {
                $this->entityManager->getConnection()->executeStatement('UPDATE import_log SET finished_at = :finished, errors_count = errors_count + 1, created_count = 0 WHERE id = :id AND company_id = :company', ['finished' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'id' => $importLog->getId(), 'company' => $company->getId()]);
            }
            throw $exception;
        } finally {
            try {
                // На закрытом EM flush() бросил бы и подменил исходное исключение.
                if ($this->entityManager->isOpen()) {
                    $this->importLogger->finish($importLog);
                    $this->entityManager->flush();
                }
            } finally {
                try {
                    if ($accountLockHeld) {
                        $this->accountLock->release((string) $company->getId(), (string) $account->getId());
                    }
                } finally {
                    $this->importLogLease->release($importLog->getId());
                }
            }
        }

        return ['summary' => $summary, 'importLog' => $importLog];
    }
}
