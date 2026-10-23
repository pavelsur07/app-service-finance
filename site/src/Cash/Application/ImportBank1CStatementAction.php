<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Exception\CashImportAccountBusyException;
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
        private readonly EntityManagerInterface $entityManager,
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

        try {
            $summary = $this->importService->import(
                $preview,
                $account,
                $overwrite,
                fn () => $this->entityManager->flush(),
                ['preview' => false, 'import_log' => $importLog] + $context,
            );
        } finally {
            // На закрытом EM flush() бросил бы и подменил исходное исключение.
            if ($this->entityManager->isOpen()) {
                $this->importLogger->finish($importLog);
                $this->entityManager->flush();
            }
        }

        return ['summary' => $summary, 'importLog' => $importLog];
    }
}
