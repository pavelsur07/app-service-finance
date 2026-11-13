<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\Import\File;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Infrastructure\Import\File\CashFileStorage;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\CounterpartyType;
use App\Company\Facade\CounterpartyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @phpstan-import-type InputRow from CashFileDescriptionPlan
 *
 * @phpstan-type NormalizedRow array{ok: bool, errors: list<string>, occurredAt: ?\DateTimeImmutable, direction: ?\App\Cash\Enum\Transaction\CashDirection, amount: ?string, counterpartyName: ?string, description: ?string, currency: string, docNumber: ?string, externalId: ?string, raw: array<string, mixed>}
 */
final class CashFileImportService
{
    private const IMPORT_SOURCE = 'file';

    /** @var array<string, Counterparty> */
    private array $counterpartyCache = [];

    /** @var list<CashTransaction> */
    private array $batchTransactions = [];

    /**
     * dedupe_hash → номер документа ('' — без номера) → сколько строк файла с этим ключом
     * закрываются транзакциями, бывшими в БД до импорта (см. CashFileDuplicatePlanner::plan()).
     *
     * @var array<string, array<string, int>>
     */
    private array $matchQuota = [];

    public function __construct(
        private readonly CashFileNormalizedRows $rows,
        private readonly CashFileDuplicatePlanner $duplicatePlanner,
        private readonly CounterpartyNameNormalizer $counterpartyNameNormalizer,
        private readonly CounterpartyFacade $counterpartyFacade,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly ImportLogger $importLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountBalanceService $accountBalanceService,
        private readonly CashFileStorage $storage,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
    ) {
    }

    /**
     * Строки пишутся пачками: после каждой вызывается $commitBatch точки входа (Handler), сервис
     * сам не коммитит.
     *
     * @param \Closure(): void $commitBatch
     * @param array<int, InputRow>|null $allowedRows
     */
    public function import(CashFileImportJob $job, \Closure $commitBatch, ?array $allowedRows = null): void
    {
        $this->importRows($job, $commitBatch, $allowedRows);
    }

    /**
     * @param \Closure(): void $commitBatch
     * @param array<int, InputRow>|null $allowedRows
     */
    private function importRows(CashFileImportJob $job, \Closure $commitBatch, ?array $allowedRows): void
    {
        $company = $job->getCompany();
        $account = $job->getMoneyAccount();
        if ($account->getCompany()->getId() !== $company->getId()) {
            throw new \DomainException('Счёт импорта принадлежит другой компании.');
        }
        FiatCurrency::fromCode($account->getCurrency());
        if ([] === $allowedRows) {
            if (null !== $job->getImportLog()) {
                $this->importLogger->finish($job->getImportLog());
            }
            $commitBatch();

            return;
        }

        // Файл читается воркером через объектное хранилище: скачиваем во временную
        // локальную копию (readers OpenSpout требуют реальный путь), обрабатываем,
        // TemporaryLocalFile гарантированно удаляет её после.
        $this->storage->withJobFile(
            $job,
            // Устаревший .xls конвертируется в .xlsx: ридера для него у OpenSpout нет,
            // и раньше задача молча умирала в очереди фатальной ошибкой.
            function (string $filePath) use ($job, $commitBatch, $allowedRows): void {
                $expectedHash = $job->getOptions()['description_content_hash'] ?? null;
                if (null !== $allowedRows && (!is_string($expectedHash) || !hash_equals($expectedHash, (string) hash_file('sha256', $filePath)))) {
                    throw new \DomainException('Файл изменился после подтверждения.');
                }
                $this->storage->withReadablePath($filePath, fn (string $readablePath) => $this->readAndPersist($readablePath, $job, $commitBatch, $allowedRows));
            },
            false,
        );
    }

    /**
     * @param \Closure(): void $commitBatch
     * @param array<int, InputRow>|null $allowedRows
     */
    private function readAndPersist(string $filePath, CashFileImportJob $job, \Closure $commitBatch, ?array $allowedRows): void
    {
        $company = $job->getCompany();
        $moneyAccount = $job->getMoneyAccount();
        $importLog = $job->getImportLog();
        $mapping = $job->getMapping();
        /** @var string $companyId Company constructor initializes its UUID; persisted IDs are non-null. */
        $companyId = $company->getId();
        /** @var string $accountId MoneyAccount constructor initializes its UUID; persisted IDs are non-null. */
        $accountId = $moneyAccount->getId();
        $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate($companyId, null, null);
        $systemProject = $this->entityManager->getReference(
            ProjectDirection::class,
            $responsibilityPair->projectDirectionId
        );

        // Сервис живёт в воркере между сообщениями: хвост упавшего импорта не
        // должен попасть в пачку следующего.
        $this->batchTransactions = [];
        $this->counterpartyCache = [];
        $this->matchQuota = [];

        $created = 0;
        $createdMinDate = null;
        $createdMaxDate = null;
        $batchSize = 200;
        $batchCount = 0;

        // Первый проход: сколько строк каждого ключа в файле и сколько из них уже есть в БД.
        // A confirmed plan has already allocated the full file against the current
        // snapshot under the account lock. Counting again on its filtered create
        // rows would consume existing matches twice and lose legitimate payments.
        $this->matchQuota = null === $allowedRows ? $this->duplicatePlanner->plan($filePath, $job) : [];

        try {
            try {
                foreach ($this->rows->iterate($filePath, $mapping, $moneyAccount->getCurrency(), $allowedRows) as $item) {
                    $rowByHeader = $item['row'];
                    $normalized = $item['normalized'];

                    // The clock or parser context can change even after the final
                    // re-plan. Validate the exact values about to reach the entity.
                    if (null !== $allowedRows && self::planInput($normalized, $item['rowNumber']) !== ($allowedRows[$item['rowNumber']] ?? null)) {
                        $current = $job->getOptions();
                        $current['creation_conflicts']['r'.$item['rowNumber']] = 'Данные строки изменились после предпросмотра';
                        $job->setOptions($current);
                        continue;
                    }

                    if (false === $normalized['ok']) {
                        if ($importLog) {
                            $this->importLogger->incError($importLog);
                        }
                        continue;
                    }

                    $occurredAt = $normalized['occurredAt'];
                    $direction = $normalized['direction'];
                    $amount = $normalized['amount'];
                    $currency = $normalized['currency'];
                    $description = $normalized['description'];
                    $docNumber = $normalized['docNumber'];
                    $counterpartyName = $normalized['counterpartyName'];

                    if (!$occurredAt || !$direction || !$amount) {
                        if ($importLog) {
                            $this->importLogger->incError($importLog);
                        }
                        continue;
                    }

                    $occurredAtUtc = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
                    $amountMinor = (int) str_replace('.', '', $amount);
                    $dedupeHash = $this->duplicatePlanner->hash(
                        $companyId,
                        $accountId,
                        $occurredAtUtc,
                        $amountMinor,
                        $description ?? ''
                    );

                    if ($this->consumeExistingMatch($dedupeHash, $docNumber)) {
                        if ($importLog) {
                            $this->importLogger->incSkippedDuplicate($importLog);
                        }
                        continue;
                    }

                    $transaction = new CashTransaction(
                        Uuid::uuid4()->toString(),
                        $company,
                        $moneyAccount,
                        $direction,
                        $amount,
                        $currency,
                        $occurredAt,
                    );
                    $transaction->setDedupeHash($dedupeHash);
                    $transaction->setImportSource(self::IMPORT_SOURCE);
                    $transaction->setDocNumber($docNumber);
                    $transaction->setDescription($description);
                    $transaction->setProjectDirection($systemProject);
                    $transaction->setResponsibilityCenterId($responsibilityPair->responsibilityCenterId);
                    $transaction->setBookedAt($occurredAt);
                    $transaction->setRawData([
                        'row' => $rowByHeader,
                        'mapping' => $mapping,
                    ]);
                    $transaction->setUpdatedAt(new \DateTimeImmutable());
                    // Номер документа не идёт в external_id: он повторяется между годами
                    // и счетами, а uniq_cashflow_import валил на нём весь импорт.
                    // Дубли файла ловит isAlreadyImported() по dedupeHash и doc_number.

                    if (null !== $counterpartyName) {
                        $counterparty = $this->getOrCreateCounterparty($companyId, $counterpartyName, $company);
                        $transaction->setCounterparty($counterparty);
                    } else {
                        $transaction->setCounterparty(null);
                    }

                    $this->entityManager->persist($transaction);
                    $this->batchTransactions[] = $transaction;

                    ++$created;
                    if ($importLog) {
                        $this->importLogger->incCreated($importLog);
                    }

                    if (null === $createdMinDate || $occurredAt < $createdMinDate) {
                        $createdMinDate = $occurredAt;
                    }
                    if (null === $createdMaxDate || $occurredAt > $createdMaxDate) {
                        $createdMaxDate = $occurredAt;
                    }

                    ++$batchCount;
                    if ($batchCount >= $batchSize) {
                        $this->flushBatch($commitBatch);
                        $batchCount = 0;
                    }
                }
            } finally {
                if ($batchCount > 0) {
                    $this->flushBatch($commitBatch);
                }
            }

            if ($created > 0 && null !== $createdMinDate) {
                $today = new \DateTimeImmutable('today');
                $toDate = $createdMaxDate ?? $createdMinDate;
                if ($createdMinDate <= $today) {
                    $toDate = $today;
                }
                $this->accountBalanceService->recalculateDailyRange($company, $moneyAccount, $createdMinDate, $toDate);
            }
        } finally {
            // На закрытом EM finish() бросил бы и подменил исходное исключение;
            // журнал тогда закрывает CashFileImportHandler после resetManager().
            if ($importLog && $this->entityManager->isOpen()) {
                $this->importLogger->finish($importLog);
            }
        }
    }

    /**
     * Пересчёт остатков по строкам, которые упавший импорт успел зафиксировать:
     * пачки коммитятся по отдельности, а штатный пересчёт стоит после цикла.
     * Повторная загрузка того же файла пропустит эти строки как дубли и диапазон
     * не пересчитает.
     */
    public function recalculateCommittedRows(CashFileImportJob $job): void
    {
        $startedAt = $job->getStartedAt();
        if (null === $startedAt) {
            return;
        }

        $company = $job->getCompany();
        $account = $job->getMoneyAccount();
        $range = $this->cashTransactionRepository->findOccurredRangeByCompanyAccountSourceCreatedSince(
            (string) $company->getId(),
            (string) $account->getId(),
            self::IMPORT_SOURCE,
            $startedAt,
        );
        if (null === $range) {
            return;
        }

        [$from, $to] = $range;
        $today = new \DateTimeImmutable('today');
        if ($from <= $today) {
            $to = $today;
        }
        $this->accountBalanceService->recalculateDailyRange($company, $account, $from, $to);
    }

    /**
     * Строки файла после нормализации. Ридер закрывается, когда обход закончен или прерван.
     *
     * @param array<string, mixed> $mapping
     *
     * @return \Generator<int, array{rowNumber: int, row: array<string, ?string>, normalized: NormalizedRow}>
     */
    public function normalizedRows(string $filePath, array $mapping, string $currency): \Generator
    {
        yield from $this->rows->iterate($filePath, $mapping, $currency);
    }

    /**
     * @param NormalizedRow $normalized
     *
     * @return InputRow
     */
    public static function planInput(array $normalized, int $rowNumber): array
    {
        $errors = $normalized['errors'];
        $tooLong = mb_strlen($normalized['description'] ?? '', 'UTF-8') > CashTransaction::DESCRIPTION_MAX_LENGTH;
        if ($tooLong) {
            $errors[] = sprintf('Описание не должно превышать %d символа.', CashTransaction::DESCRIPTION_MAX_LENGTH);
        }

        return [
            'rowId' => 'r'.$rowNumber,
            'date' => $normalized['occurredAt']?->format('Y-m-d') ?? '',
            'amount' => $normalized['amount'] ?? '',
            'direction' => null === $normalized['direction'] ? '' : $normalized['direction']->value,
            'currency' => $normalized['currency'],
            'docNumber' => $normalized['docNumber'],
            'description' => $normalized['description'],
            'counterparty' => $normalized['counterpartyName'],
            'ok' => $normalized['ok'] && !$tooLong,
            'errors' => $errors,
        ];
    }

    private function consumeExistingMatch(string $dedupeHash, ?string $docNumber): bool
    {
        $doc = trim((string) $docNumber);
        if (($this->matchQuota[$dedupeHash][$doc] ?? 0) > 0) {
            --$this->matchQuota[$dedupeHash][$doc];

            return true;
        }

        return false;
    }

    private function getOrCreateCounterparty(string $companyId, string $name, Company $company): Counterparty
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new \RuntimeException('Counterparty name is empty.');
        }

        // Матчинг идёт по нормализованному ключу, а не по строке из файла: иначе
        // «ООО "Ромашка"» и «"Ромашка" ООО» из разных выписок создают двух
        // контрагентов. ОПФ входит в ключ, чтобы ООО и АО с одним названием
        // остались разными юрлицами.
        $normalizedName = $this->counterpartyNameNormalizer->normalize($trimmedName);

        $cacheKey = $companyId.':'.$normalizedName->core.':'.($normalizedName->legalFormHint ?? '');
        if (isset($this->counterpartyCache[$cacheKey])) {
            return $this->counterpartyCache[$cacheKey];
        }

        // Ищет и создаёт (persist без flush) Company — импорт только кэширует.
        return $this->counterpartyCache[$cacheKey] = $this->counterpartyFacade->findOrCreateByNormalizedName(
            $company,
            $normalizedName,
            CounterpartyType::LEGAL_ENTITY,
        );
    }

    /**
     * Отсоединяет только сущности пачки. clear() в ORM 3 не принимает класс и
     * очищает весь UnitOfWork: компания, счёт, журнал и job отсоединялись, и
     * импорт падал после первой пачки или на закрытии журнала.
     *
     * @param \Closure(): void $commitBatch
     */
    private function flushBatch(\Closure $commitBatch): void
    {
        $commitBatch();
        foreach ($this->batchTransactions as $transaction) {
            $this->entityManager->detach($transaction);
        }
        foreach ($this->counterpartyCache as $counterparty) {
            $this->entityManager->detach($counterparty);
        }
        $this->batchTransactions = [];
        $this->counterpartyCache = [];
    }
}
