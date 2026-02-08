<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ОРКЕСТРАТОР.
 * Связывает Reader, Normalizer и Persister.
 */
final class CashFileImportService
{
    public function __construct(
        private readonly CashFileImportReader $reader,
        private readonly CashFileRowNormalizer $normalizer,
        private readonly CashTransactionPersister $persister,
        private readonly AccountBalanceService $balanceService,
        private readonly ImportLogger $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function import(string $jobId): void
    {
        $job = $this->entityManager->find(CashFileImportJob::class, $jobId);
        if (!$job) throw new \RuntimeException("Job not found");

        $importLog = $job->getImportLog();
        if ($importLog) {
            // Принудительно сохраняем время старта
            $this->entityManager->flush();
        }

        $createdMinDate = null;
        $createdMaxDate = null;
        $hasCreated = false;

        // --- ГЛАВНЫЙ ЦИКЛ ---
        foreach ($this->reader->read($job) as $row) {

            // 1. Нормализация
            $result = $this->normalizer->normalize(
                $row,
                $job->getMapping(),
                $job->getMoneyAccount()->getCurrency()
            );

            if (!$result['ok']) {
                if ($importLog) $this->logger->incError($importLog);
                continue;
            }

            // 2. Валидация обязательных полей
            if (!$result['occurredAt'] || !$result['amount'] || !$result['direction']) {
                if ($importLog) $this->logger->incError($importLog);
                continue;
            }

            // 3. Сохранение
            $this->persister->persist($result, $job);

            // 4. Сбор дат для пересчета баланса
            $date = $result['occurredAt'];
            if (!$createdMinDate || $date < $createdMinDate) $createdMinDate = $date;
            if (!$createdMaxDate || $date > $createdMaxDate) $createdMaxDate = $date;
            $hasCreated = true;
        }

        // --- ФИНАЛ ---

        // Сбрасываем остатки в базу
        $this->persister->flush();

        // Завершаем лог (ставим дату финиша)
        if ($importLog) {
            $this->logger->finish($importLog);
            $this->entityManager->flush();
        }

        // Пересчитываем балансы
        if ($hasCreated && $createdMinDate) {
            $toDate = $createdMaxDate ?? $createdMinDate;
            $today = new \DateTimeImmutable('today');
            if ($toDate > $today) $toDate = $today;

            $this->balanceService->recalculateDailyRange(
                $job->getCompany(),
                $job->getMoneyAccount(),
                $createdMinDate,
                $toDate
            );
        }
    }
}
