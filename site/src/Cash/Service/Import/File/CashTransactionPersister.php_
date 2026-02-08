<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Enum\CounterpartyType;
use App\Entity\Counterparty;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Отвечает ТОЛЬКО за сохранение сущностей, дедупликацию и управление памятью Doctrine.
 */
final class CashTransactionPersister
{
    private array $counterpartyCache = [];
    private int $batchSize = 50; // Маленький батч, чтобы логи обновлялись часто
    private int $batchCount = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionRepository $transactionRepo,
        private readonly CounterpartyRepository $counterpartyRepo,
        private readonly ImportLogger $importLogger,
    ) {
    }

    public function persist(array $normalizedData, CashFileImportJob $job): void
    {
        $occurredAt = $normalizedData['occurredAt'];
        $amount = $normalizedData['amount'];
        $direction = $normalizedData['direction'];
        $company = $job->getCompany();
        $account = $job->getMoneyAccount();
        $log = $job->getImportLog();

        // 1. Создаем Хэш для проверки дублей
        $dedupeHash = $this->makeDedupeHash(
            $company->getId(),
            $account->getId(),
            $occurredAt,
            $amount,
            $normalizedData['description'] ?? ''
        );

        // 2. Проверка на дубликат
        if ($this->transactionRepo->existsByCompanyAndDedupe($company->getId(), $dedupeHash)) {
            if ($log) $this->importLogger->incSkippedDuplicate($log);
            return;
        }

        // 3. Создаем транзакцию
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company, $account, $direction, $amount,
            $normalizedData['currency'], $occurredAt
        );
        $transaction->setDedupeHash($dedupeHash);
        $transaction->setImportSource('file');
        $transaction->setDocNumber($normalizedData['docNumber']);
        $transaction->setDescription($normalizedData['description']);
        $transaction->setBookedAt($occurredAt);
        $transaction->setRawData(['mapping' => $job->getMapping()]); // Можно добавить raw row
        $transaction->setUpdatedAt(new \DateTimeImmutable());

        if (!empty($normalizedData['docNumber'])) {
            $transaction->setExternalId(trim($normalizedData['docNumber']));
        }

        // 4. Работа с контрагентом (с кешированием)
        if (!empty($normalizedData['counterpartyName'])) {
            $cp = $this->getCounterparty($company, $normalizedData['counterpartyName']);
            $transaction->setCounterparty($cp);
        }

        $this->entityManager->persist($transaction);

        // 5. Обновляем счетчик
        if ($log) $this->importLogger->incCreated($log);

        // 6. Батчинг (сохранение и очистка)
        $this->batchCount++;
        if ($this->batchCount >= $this->batchSize) {
            $this->flushAndClear();
        }
    }

    public function flush(): void
    {
        if ($this->batchCount > 0) {
            $this->flushAndClear();
        }
    }

    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        // ВАЖНО: Очищаем только транзакции и контрагентов, чтобы не отвязать Job и Log
        $this->entityManager->clear(CashTransaction::class);
        $this->entityManager->clear(Counterparty::class);
        $this->counterpartyCache = [];
        $this->batchCount = 0;
    }

    private function getCounterparty(Company $company, string $name): Counterparty
    {
        $name = trim($name);
        $key = $company->getId() . ':' . mb_strtolower($name);

        if (isset($this->counterpartyCache[$key])) {
            return $this->counterpartyCache[$key];
        }

        $existing = $this->counterpartyRepo->findOneBy(['company' => $company, 'name' => $name]);
        if ($existing) {
            return $this->counterpartyCache[$key] = $existing;
        }

        $newCp = new Counterparty(Uuid::uuid4()->toString(), $company, $name, CounterpartyType::LEGAL_ENTITY);
        $this->entityManager->persist($newCp);

        return $this->counterpartyCache[$key] = $newCp;
    }

    private function makeDedupeHash(string $cid, string $accId, \DateTimeImmutable $date, string $amt, string $desc): string
    {
        // Конвертация даты в UTC и суммы в копейки
        $dateStr = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $amtInt = (int)str_replace('.', '', $amt);
        $descNorm = mb_strtolower(preg_replace('/\s+/u', ' ', preg_replace('/[\(\)\[\]\{\}]/u', ' ', $desc) ?? '') ?? '');

        return hash('sha256', "$cid|$accId|$dateStr|$amtInt|$descNorm");
    }
}
